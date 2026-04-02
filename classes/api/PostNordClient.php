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
 * HTTP client for the PostNord Service Points V5 API.
 * Responsible only for API communication and response parsing.
 * Configuration (API key, country code) is read from the ShippingType property
 * column, not from a separate settings model.
 */
class PostNordClient
{
    private const API_BASE_URL = 'https://api2.postnord.com/rest/businesslocation/v5/servicepoint/findNearestByAddress';

    /**
     * @param string $sApiKey PostNord developer API key
     * @param string $sCountryCode ISO 3166-1 alpha-2 country code
     */
    public function __construct(
        private readonly string $sApiKey,
        private readonly string $sCountryCode = 'NO',
    ) {
    }

    /**
     * Create a PostNordClient from a ShippingType item's property values.
     * API key and country code are stored as property[postnord_api_key] and
     * property[postnord_country_code] on the ShippingType form.
     */
    public static function fromShippingType(ShippingTypeItem $obShippingTypeItem): self
    {
        $arProperty = $obShippingTypeItem->property;
        $arProperty = is_array($arProperty) ? $arProperty : [];

        $mApiKey = $arProperty['postnord_api_key'] ?? '';
        $mCountryCode = $arProperty['postnord_country_code'] ?? 'NO';

        return new self(
            self::mixedToString($mApiKey),
            self::mixedToString($mCountryCode),
        );
    }

    /**
     * Test the API connection using a known postal code for the configured country.
     * Returns a result array with 'success' (bool) and 'message' (string).
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if ($this->sApiKey === '') {
            return ['success' => false, 'message' => 'API key is empty.'];
        }

        $arTestPostalCodes = [
            'NO' => '0001',
            'LV' => '1001',
            'LT' => '01001',
        ];

        $sTestPostalCode = $arTestPostalCodes[$this->sCountryCode] ?? '0001';

        try {
            $obResponse = Http::get(self::API_BASE_URL, [
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
     * Find nearest service points by postal code
     *
     * @param string $sPostalCode Postal code to search near
     * @param int $iMaxResults Maximum number of results to return
     * @return array<int, array<string, mixed>> Parsed service point data
     */
    public function findNearestByAddress(string $sPostalCode, int $iMaxResults = 10): array
    {
        if ($sPostalCode === '' || $this->sApiKey === '') {
            return [];
        }

        try {
            $obResponse = Http::get(self::API_BASE_URL, [
                'apikey'                 => $this->sApiKey,
                'countryCode'            => $this->sCountryCode,
                'postalCode'             => $sPostalCode,
                'numberOfServicePoints'  => $iMaxResults,
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
     * Safely convert mixed value to string
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
     * Parse service points from the PostNord V5 API response
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
     * Parse a single service point from the API response
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
            'service_point_id' => self::mixedToString(data_get($arPointData, 'servicePointId', '')),
            'name'             => self::mixedToString(data_get($arPointData, 'name', '')),
            'street_name'      => self::mixedToString(data_get($arAddress, 'streetName', '')),
            'street_number'    => self::mixedToString(data_get($arAddress, 'streetNumber', '')),
            'postal_code'      => self::mixedToString(data_get($arAddress, 'postalCode', '')),
            'city'             => self::mixedToString(data_get($arAddress, 'city', '')),
            'country_code'     => self::mixedToString(data_get($arAddress, 'countryCode', '')),
            'northing'           => is_numeric($mNorthing) ? (float) $mNorthing : null,
            'easting'            => is_numeric($mEasting) ? (float) $mEasting : null,
            'distance_in_meters' => $this->extractDistanceInMeters($arPointData),
        ];
    }

    /**
     * Extract route distance in meters from a service point
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
     * Extract the first coordinate from a service point's coordinates array
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
