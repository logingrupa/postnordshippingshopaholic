<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShipping\Classes\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Logingrupa\PostNordShipping\Models\Settings;

/**
 * Class PostNordClient
 * @package Logingrupa\PostNordShipping\Classes\Api
 *
 * HTTP client for the PostNord Service Points V5 API.
 * Responsible only for API communication and response parsing.
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
     * Create a PostNordClient from backend settings
     */
    public static function fromSettings(): self
    {
        $mApiKey = Settings::get('api_key', '');
        $mCountryCode = Settings::get('country_code', 'NO');

        return new self(
            self::mixedToString($mApiKey),
            self::mixedToString($mCountryCode),
        );
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

            $mAddress = data_get($mPointData, 'visitingAddress');
            $arAddress = is_array($mAddress) ? $mAddress : [];

            $mCoordinateList = data_get($mPointData, 'coordinates');
            $arCoordinateList = is_array($mCoordinateList) ? $mCoordinateList : [];
            $arFirstCoordinate = $arCoordinateList !== [] && is_array($arCoordinateList[0])
                ? $arCoordinateList[0]
                : [];

            $mNorthing = data_get($arFirstCoordinate, 'northing');
            $mEasting = data_get($arFirstCoordinate, 'easting');

            $arServicePointList[] = [
                'service_point_id' => self::mixedToString(data_get($mPointData, 'servicePointId', '')),
                'name'             => self::mixedToString(data_get($mPointData, 'name', '')),
                'street_name'      => self::mixedToString(data_get($arAddress, 'streetName', '')),
                'street_number'    => self::mixedToString(data_get($arAddress, 'streetNumber', '')),
                'postal_code'      => self::mixedToString(data_get($arAddress, 'postalCode', '')),
                'city'             => self::mixedToString(data_get($arAddress, 'city', '')),
                'country_code'     => self::mixedToString(data_get($arAddress, 'countryCode', '')),
                'northing'         => is_numeric($mNorthing) ? (float) $mNorthing : null,
                'easting'          => is_numeric($mEasting) ? (float) $mEasting : null,
            ];
        }

        return $arServicePointList;
    }
}
