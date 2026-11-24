<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;

/**
 * Class PostNordClient
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * HTTP client for PostNord pickup point lookup.
 * Supports both:
 *  - PostNord Nordic Service Points V5 API (developer.postnord.com — API key)
 *  - PostNord Norway B2C Products API (developer.postnord.no — OAuth2 Client Credentials)
 *
 * Configuration is read from the ShippingType property column.
 */
class PostNordClient
{
    public const MODE_NORDIC = 'nordic';
    public const MODE_NORWAY = 'norway';

    private const NORDIC_PROD_URL = 'https://api2.postnord.com/rest/businesslocation/v5/servicepoints/nearest/byaddress';
    private const NORDIC_SANDBOX_URL = 'https://atapi2.postnord.com/rest/businesslocation/v5/servicepoints/nearest/byaddress';
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 5;

    /**
     * @param string $sApiKey PostNord Nordic developer API key
     * @param string $sCountryCode ISO 3166-1 alpha-2 country code
     * @param bool $bIsSandbox Whether to target the sandbox host
     * @param PostNordNorwayClient|null $obNorwayClient Delegate client when running in Norway OAuth2 mode
     */
    public function __construct(
        private readonly string $sApiKey,
        private readonly string $sCountryCode = 'NO',
        private readonly bool $bIsSandbox = false,
        private readonly ?PostNordNorwayClient $obNorwayClient = null,
    ) {
    }

    /**
     * Create a PostNordClient from a ShippingType item's property values.
     */
    public static function fromShippingType(ShippingTypeItem $obShippingTypeItem): self
    {
        /** @var mixed $mProperty */
        $mProperty = $obShippingTypeItem->property;
        $arProperty = is_array($mProperty) ? $mProperty : [];

        return self::fromConfig($arProperty);
    }

    /**
     * Create a PostNordClient from a ShippingType property array.
     *
     * @param array<mixed, mixed> $arProperty ShippingType property values
     */
    public static function fromConfig(array $arProperty): self
    {
        $sApiKey = trim(self::mixedToString($arProperty['postnord_api_key'] ?? ''));
        $sCountryCode = trim(self::mixedToString($arProperty['postnord_country_code'] ?? 'NO'));
        $sCountryCode = $sCountryCode !== '' ? $sCountryCode : 'NO';
        $bIsSandbox = filter_var($arProperty['postnord_sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $sClientId = trim(self::mixedToString($arProperty['postnord_client_id'] ?? ''));
        $sApiMode = trim(self::mixedToString($arProperty['postnord_api_mode'] ?? ''));

        $bUseNorway = $sApiMode === self::MODE_NORWAY
            || ($sApiMode === '' && $sClientId !== '' && $sApiKey === '');

        if ($bUseNorway) {
            $sClientSecret = trim(self::mixedToString($arProperty['postnord_client_secret'] ?? ''));
            $sCustomerId = trim(self::mixedToString($arProperty['postnord_customer_id'] ?? ''));
            $sSenderPostalCode = trim(self::mixedToString($arProperty['postnord_sender_postal_code'] ?? '1528'));

            return new self(
                '',
                'NO',
                $bIsSandbox,
                new PostNordNorwayClient(
                    $sClientId,
                    $sClientSecret,
                    $sCustomerId,
                    $sSenderPostalCode !== '' ? $sSenderPostalCode : '1528',
                    $bIsSandbox,
                ),
            );
        }

        return new self($sApiKey, $sCountryCode, $bIsSandbox);
    }

    /**
     * Test the API connection using a known postal code for the configured country.
     * Returns a result array with 'success' (bool) and 'message' (string).
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if ($this->obNorwayClient !== null) {
            return $this->obNorwayClient->testConnection();
        }

        if ($this->sApiKey === '') {
            return ['success' => false, 'message' => 'API key is empty.'];
        }

        $sTestPostalCode = $this->resolveTestPostalCode();

        try {
            $obResponse = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($this->getNordicApiUrl(), [
                    'returnType'            => 'json',
                    'apikey'                => $this->sApiKey,
                    'countryCode'           => $this->sCountryCode,
                    'postalCode'            => $sTestPostalCode,
                    'numberOfServicePoints' => 1,
                ]);

            if ($obResponse->status() === 401 || $obResponse->status() === 403) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed (HTTP ' . $obResponse->status() . '). Check the API key.',
                ];
            }

            if (!$obResponse->successful()) {
                return [
                    'success' => false,
                    'message' => 'PostNord API returned HTTP ' . $obResponse->status() . '.',
                ];
            }

            $arData = $obResponse->json();

            if (!is_array($arData)) {
                return ['success' => false, 'message' => 'PostNord API returned an unexpected response format.'];
            }

            $mServicePoints = data_get($arData, 'servicePointInformationResponse.servicePoints');
            $iCount = is_array($mServicePoints) ? count($mServicePoints) : 0;

            return [
                'success' => true,
                'message' => 'Connection successful. Found ' . $iCount . ' service point(s) near postal code ' . $sTestPostalCode . ' (' . $this->sCountryCode . ').',
            ];
        } catch (\Exception $obException) {
            Log::warning('PostNord API test connection failed', [
                'message' => $obException->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Connection error: ' . $obException->getMessage()];
        }
    }

    /**
     * Find nearest service points by postal code.
     *
     * @param string $sPostalCode Postal code to search near
     * @param int $iMaxResults Maximum number of results to return
     * @return array<int, array<string, mixed>> Parsed service point data
     */
    public function findNearestByAddress(string $sPostalCode, int $iMaxResults = 10): array
    {
        if ($this->obNorwayClient !== null) {
            return $this->obNorwayClient->findNearestByAddress($sPostalCode, $iMaxResults);
        }

        if ($sPostalCode === '' || $this->sApiKey === '') {
            return [];
        }

        try {
            $obResponse = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($this->getNordicApiUrl(), [
                    'returnType'            => 'json',
                    'apikey'                => $this->sApiKey,
                    'countryCode'           => $this->sCountryCode,
                    'postalCode'            => $sPostalCode,
                    'numberOfServicePoints' => $iMaxResults,
                ]);

            if (!$obResponse->successful()) {
                Log::warning('PostNord API returned non-success status', [
                    'status'      => $obResponse->status(),
                    'postal_code' => $sPostalCode,
                ]);

                return [];
            }

            $arResponseData = $obResponse->json();

            if (!is_array($arResponseData)) {
                return [];
            }

            return $this->parseServicePointList($arResponseData);
        } catch (\Exception $obException) {
            Log::warning('PostNord API request failed', [
                'message'     => $obException->getMessage(),
                'postal_code' => $sPostalCode,
            ]);

            return [];
        }
    }

    /**
     * Return the Nordic Service Points V5 endpoint URL for the active environment.
     */
    private function getNordicApiUrl(): string
    {
        return $this->bIsSandbox ? self::NORDIC_SANDBOX_URL : self::NORDIC_PROD_URL;
    }

    /**
     * Resolve a known test postal code for the configured country.
     */
    private function resolveTestPostalCode(): string
    {
        $arTestPostalCodes = [
            'NO' => '0001',
            'SE' => '11122',
            'DK' => '1000',
            'FI' => '00100',
            'LV' => '1001',
            'LT' => '01001',
        ];

        return $arTestPostalCodes[$this->sCountryCode] ?? '0001';
    }

    /**
     * Safely convert mixed value to string.
     */
    private static function mixedToString(mixed $mValue): string
    {
        if (is_string($mValue)) {
            return $mValue;
        }

        if (is_int($mValue) || is_float($mValue)) {
            return (string) $mValue;
        }

        return '';
    }

    /**
     * Parse service points from the PostNord V5 API response.
     *
     * @param array<mixed, mixed> $arResponseData Raw API response
     * @return array<int, array<string, mixed>> Parsed service point list
     */
    private function parseServicePointList(array $arResponseData): array
    {
        $mServicePointListRaw = data_get(
            $arResponseData,
            'servicePointInformationResponse.servicePoints'
        );

        if (!is_array($mServicePointListRaw) || $mServicePointListRaw === []) {
            return [];
        }

        $arServicePointList = [];

        foreach ($mServicePointListRaw as $mPointData) {
            if (!is_array($mPointData)) {
                continue;
            }

            $arServicePointList[] = $this->parseOneServicePoint($mPointData);
        }

        return $arServicePointList;
    }

    /**
     * Parse a single service point from the API response.
     *
     * @param array<mixed, mixed> $arPointData Single service point data
     * @return array<string, mixed> Parsed service point
     */
    private function parseOneServicePoint(array $arPointData): array
    {
        $mAddress = data_get($arPointData, 'visitingAddress');
        $arAddress = is_array($mAddress) ? $mAddress : [];

        $arFirstCoordinate = $this->extractFirstCoordinate($arPointData);

        $mNorthing = data_get($arFirstCoordinate, 'northing');
        $mEasting = data_get($arFirstCoordinate, 'easting');

        return [
            'service_point_id'   => self::mixedToString(data_get($arPointData, 'servicePointId', '')),
            'name'               => self::mixedToString(data_get($arPointData, 'name', '')),
            'street_name'        => self::mixedToString(data_get($arAddress, 'streetName', '')),
            'street_number'      => self::mixedToString(data_get($arAddress, 'streetNumber', '')),
            'postal_code'        => self::mixedToString(data_get($arAddress, 'postalCode', '')),
            'city'               => self::mixedToString(data_get($arAddress, 'city', '')),
            'country_code'       => self::mixedToString(data_get($arAddress, 'countryCode', '')),
            'northing'           => is_numeric($mNorthing) ? (float) $mNorthing : null,
            'easting'            => is_numeric($mEasting) ? (float) $mEasting : null,
            'distance_in_meters' => $this->extractDistanceInMeters($arPointData),
        ];
    }

    /**
     * Extract route distance in meters from a service point.
     *
     * @param array<mixed, mixed> $arPointData Service point data
     * @return int|null Distance in meters or null if unavailable
     */
    private function extractDistanceInMeters(array $arPointData): ?int
    {
        $mRouteDistance = data_get($arPointData, 'routeDistance');

        if (is_numeric($mRouteDistance)) {
            return (int) $mRouteDistance;
        }

        return null;
    }

    /**
     * Extract the first coordinate from a service point's coordinates array.
     *
     * @param array<mixed, mixed> $arPointData Service point data
     * @return array<mixed, mixed> First coordinate or empty array
     */
    private function extractFirstCoordinate(array $arPointData): array
    {
        $mCoordinateList = data_get($arPointData, 'coordinates');

        if (!is_array($mCoordinateList) || $mCoordinateList === []) {
            return [];
        }

        $mFirstCoordinate = $mCoordinateList[0];

        return is_array($mFirstCoordinate) ? $mFirstCoordinate : [];
    }
}
