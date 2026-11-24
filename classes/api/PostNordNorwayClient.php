<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Class PostNordNorwayClient
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * HTTP client for PostNord Norway's Developer Portal (developer.postnord.no).
 * Authenticates via OAuth 2.0 Client Credentials against auth.postnord.no and
 * fetches pickup points (Service Points & Parcel Lockers) from the B2C Products API.
 */
class PostNordNorwayClient
{
    private const AUTH_TOKEN_URL = 'https://auth.postnord.no/auth/realms/3Scale-prod/protocol/openid-connect/token';
    private const API_PROD_URL = 'https://api2.postnord.no/rest/api/products';
    private const API_SANDBOX_URL = 'https://atapi2.postnord.no/rest/api/products';
    private const DEFAULT_TEST_POSTAL_CODE = '0150';
    private const DEFAULT_TOKEN_TTL_SECONDS = 270;
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 5;

    /**
     * @param string $sClientId OAuth2 Client ID from developer.postnord.no
     * @param string $sClientSecret OAuth2 Client Secret from developer.postnord.no
     * @param string $sCustomerId PostNord Norway customer / company ID
     * @param string $sSenderPostalCode Warehouse / consignor postal code
     * @param bool $bIsSandbox Whether to target the sandbox host (atapi2.postnord.no)
     */
    public function __construct(
        private readonly string $sClientId,
        private readonly string $sClientSecret,
        private readonly string $sCustomerId,
        private readonly string $sSenderPostalCode = '1528',
        private readonly bool $bIsSandbox = false,
    ) {
    }

    /**
     * Test OAuth2 authentication and B2C Products API connectivity.
     * Always requests a fresh token so rotated or invalid secrets fail immediately.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $sMissingError = $this->validateCredentials();
        if ($sMissingError !== null) {
            return ['success' => false, 'message' => $sMissingError];
        }

        try {
            $arTokenResult = $this->requestAccessToken();
            if ($arTokenResult['token'] === '') {
                return ['success' => false, 'message' => $arTokenResult['error']];
            }

            $obResponse = $this->sendProductsRequest($arTokenResult['token'], self::DEFAULT_TEST_POSTAL_CODE, 3);
            if (!$obResponse->successful()) {
                return ['success' => false, 'message' => $this->formatApiError($obResponse->status(), $obResponse->body())];
            }

            return $this->buildTestSuccessResult($obResponse->json());
        } catch (\Exception $obException) {
            Log::warning('PostNord Norway API test connection failed', ['message' => $obException->getMessage()]);

            return ['success' => false, 'message' => 'Connection error: ' . $obException->getMessage()];
        }
    }

    /**
     * Find nearest pickup points (service points & parcel lockers) by postal code.
     *
     * @param string $sPostalCode Consignee postal code
     * @param int $iMaxResults Maximum number of results to return
     * @return array<int, array<string, mixed>> Parsed service point data
     */
    public function findNearestByAddress(string $sPostalCode, int $iMaxResults = 10): array
    {
        if ($sPostalCode === '' || $this->validateCredentials() !== null) {
            return [];
        }

        try {
            $obResponse = $this->sendAuthenticatedProductsRequest($sPostalCode, $iMaxResults);
            if ($obResponse === null || !$obResponse->successful()) {
                $this->logFailedResponse($obResponse, $sPostalCode);

                return [];
            }

            $arData = $obResponse->json();

            return is_array($arData) ? PostNordNorwayResponseParser::parseProducts($arData, $iMaxResults) : [];
        } catch (\Exception $obException) {
            Log::warning('PostNord Norway API request failed', [
                'message'     => $obException->getMessage(),
                'postal_code' => $sPostalCode,
            ]);

            return [];
        }
    }

    /**
     * Validate required credentials for PostNord Norway API.
     */
    private function validateCredentials(): ?string
    {
        if ($this->sClientId === '' || $this->sClientSecret === '') {
            return 'Client ID and Client Secret are required for PostNord Norway API.';
        }

        if ($this->sCustomerId === '') {
            return 'Customer ID is required for PostNord Norway API.';
        }

        return null;
    }

    /**
     * Send the products request with a cached JWT, retrying once on HTTP 401.
     */
    private function sendAuthenticatedProductsRequest(string $sPostalCode, int $iMaxResults): ?Response
    {
        $sToken = $this->getCachedAccessToken();
        if ($sToken === '') {
            return null;
        }

        $obResponse = $this->sendProductsRequest($sToken, $sPostalCode, $iMaxResults);
        if ($obResponse->status() !== 401) {
            return $obResponse;
        }

        Cache::forget($this->getTokenCacheKey());
        $sFreshToken = $this->getCachedAccessToken();

        return $sFreshToken !== '' ? $this->sendProductsRequest($sFreshToken, $sPostalCode, $iMaxResults) : $obResponse;
    }

    /**
     * Return a cached JWT access token, or fetch and cache a new one.
     */
    private function getCachedAccessToken(): string
    {
        $sCacheKey = $this->getTokenCacheKey();
        $mCached = Cache::get($sCacheKey);

        if (is_string($mCached) && $mCached !== '') {
            return $mCached;
        }

        $arResult = $this->requestAccessToken();
        if ($arResult['token'] === '') {
            return '';
        }

        Cache::put($sCacheKey, $arResult['token'], max(30, $arResult['expires_in'] - 30));

        return $arResult['token'];
    }

    /**
     * Build a deterministic cache key scoped to this credential pair.
     */
    private function getTokenCacheKey(): string
    {
        return 'postnord_no_jwt_' . hash('sha256', $this->sClientId . ':' . $this->sClientSecret);
    }

    /**
     * Request a fresh OAuth 2.0 Client Credentials JWT from auth.postnord.no.
     *
     * @return array{token: string, expires_in: int, error: string}
     */
    private function requestAccessToken(): array
    {
        $obResponse = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->asForm()
            ->post(self::AUTH_TOKEN_URL, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->sClientId,
                'client_secret' => $this->sClientSecret,
            ]);

        if (!$obResponse->successful()) {
            return [
                'token'      => '',
                'expires_in' => 0,
                'error'      => 'OAuth2 authentication failed (HTTP ' . $obResponse->status() . '). Check Client ID and Client Secret.',
            ];
        }

        $arBody = $obResponse->json();
        $mAccessToken = is_array($arBody) ? ($arBody['access_token'] ?? '') : '';
        $mExpiresIn = is_array($arBody) ? ($arBody['expires_in'] ?? self::DEFAULT_TOKEN_TTL_SECONDS) : self::DEFAULT_TOKEN_TTL_SECONDS;

        if (!is_string($mAccessToken) || $mAccessToken === '') {
            return [
                'token'      => '',
                'expires_in' => 0,
                'error'      => 'OAuth2 response did not contain an access token.',
            ];
        }

        return [
            'token'      => $mAccessToken,
            'expires_in' => is_numeric($mExpiresIn) ? (int) $mExpiresIn : self::DEFAULT_TOKEN_TTL_SECONDS,
            'error'      => '',
        ];
    }

    /**
     * Send POST /rest/api/products request with Bearer token.
     */
    private function sendProductsRequest(string $sAccessToken, string $sPostalCode, int $iMaxResults): Response
    {
        $sEndpoint = $this->bIsSandbox ? self::API_SANDBOX_URL : self::API_PROD_URL;
        $sSenderCode = $this->sSenderPostalCode !== '' ? $this->sSenderPostalCode : '1528';

        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withToken($sAccessToken)
            ->acceptJson()
            ->post($sEndpoint, [
                'settings' => [
                    'customerId' => $this->sCustomerId,
                    'maxSpCount' => $iMaxResults,
                ],
                'consignment' => [
                    'consignor' => [
                        'postalCode' => $sSenderCode,
                    ],
                    'consignee' => [
                        'postalCode' => $sPostalCode,
                    ],
                ],
            ]);
    }

    /**
     * Format testConnection success payload from decoded JSON response.
     *
     * @return array{success: bool, message: string}
     */
    private function buildTestSuccessResult(mixed $mData): array
    {
        if (!is_array($mData)) {
            return [
                'success' => false,
                'message' => 'PostNord Norway API returned an unexpected response format.',
            ];
        }

        $arPointList = PostNordNorwayResponseParser::parseProducts($mData, 10);
        $sEnvironment = $this->bIsSandbox ? 'sandbox' : 'production';

        return [
            'success' => true,
            'message' => 'Connection successful (' . $sEnvironment . '). Found '
                . count($arPointList) . ' pickup point(s) near postal code '
                . self::DEFAULT_TEST_POSTAL_CODE . ' (NO).',
        ];
    }

    /**
     * Format a human-readable error from the B2C Products API response.
     */
    private function formatApiError(int $iStatus, string $sBody): string
    {
        $sCleanBody = trim($sBody);

        if ($iStatus === 401 || $iStatus === 403) {
            $sDetail = $sCleanBody !== '' ? ' ' . mb_substr($sCleanBody, 0, 200) : '';

            return 'PostNord Norway API authorization failed (HTTP ' . $iStatus . ').' . $sDetail;
        }

        return 'PostNord Norway API returned HTTP ' . $iStatus . '.';
    }

    /**
     * Log non-successful HTTP response when available.
     */
    private function logFailedResponse(?Response $obResponse, string $sPostalCode): void
    {
        if ($obResponse === null) {
            return;
        }

        Log::warning('PostNord Norway API returned non-success status', [
            'status'      => $obResponse->status(),
            'postal_code' => $sPostalCode,
        ]);
    }
}
