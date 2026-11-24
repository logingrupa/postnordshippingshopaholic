<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

/**
 * Class PostNordNorwayResponseParser
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Parses PostNord Norway B2C Products API responses into normalized
 * service point arrays matching the ServicePoint model schema.
 */
class PostNordNorwayResponseParser
{
    /**
     * Extract, deduplicate, and distance-sort serviceLocations across all products.
     *
     * @param array<mixed, mixed> $arResponseData Raw /rest/api/products JSON
     * @param int $iMaxResults Maximum number of points to return
     * @return array<int, array<string, mixed>> Normalized service point records
     */
    public static function parseProducts(array $arResponseData, int $iMaxResults): array
    {
        $mProductList = data_get($arResponseData, 'products');
        if (!is_array($mProductList) || $mProductList === []) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $arById */
        $arById = [];

        foreach ($mProductList as $mProduct) {
            self::collectProductLocations($mProduct, $arById);
        }

        $arResultList = array_values($arById);
        usort($arResultList, static function (array $arFirst, array $arSecond): int {
            $iFirst = is_int($arFirst['distance_in_meters'] ?? null) ? $arFirst['distance_in_meters'] : PHP_INT_MAX;
            $iSecond = is_int($arSecond['distance_in_meters'] ?? null) ? $arSecond['distance_in_meters'] : PHP_INT_MAX;

            return $iFirst <=> $iSecond;
        });

        return array_slice($arResultList, 0, max(1, $iMaxResults));
    }

    /**
     * Collect normalized serviceLocations from a single product entry into the ID map.
     *
     * @param mixed $mProduct Raw product entry
     * @param array<string, array<string, mixed>> $arById Deduplicated output map
     */
    private static function collectProductLocations(mixed $mProduct, array &$arById): void
    {
        if (!is_array($mProduct)) {
            return;
        }

        $mLocationList = data_get($mProduct, 'serviceLocations');
        if (!is_array($mLocationList)) {
            return;
        }

        foreach ($mLocationList as $mLocation) {
            if (!is_array($mLocation)) {
                continue;
            }

            $arParsed = self::parseOneLocation($mLocation);
            $sPointId = self::mixedToString($arParsed['service_point_id'] ?? '');
            if ($sPointId !== '' && !isset($arById[$sPointId])) {
                $arById[$sPointId] = $arParsed;
            }
        }
    }

    /**
     * Parse one serviceLocation entry into the ServicePoint attribute shape.
     *
     * @param array<mixed, mixed> $arLocationData Single serviceLocation object
     * @return array<string, mixed> Normalized service point record
     */
    private static function parseOneLocation(array $arLocationData): array
    {
        $mComponents = data_get($arLocationData, 'addressComponents');
        $arComponents = is_array($mComponents) ? $mComponents : [];

        $mCoordinates = data_get($arLocationData, 'coordinates');
        $arCoordinates = is_array($mCoordinates) ? $mCoordinates : [];

        $sStreet = self::mixedToString(data_get($arComponents, 'streetAddress', ''));
        if ($sStreet === '') {
            $sStreet = self::mixedToString(data_get($arLocationData, 'address', ''));
        }

        $sCountry = self::mixedToString(data_get($arComponents, 'countryCode', ''));
        $mLat = data_get($arCoordinates, 'wgs84Y');
        $mLng = data_get($arCoordinates, 'wgs84X');
        $mDistance = data_get($arLocationData, 'distance');

        return [
            'service_point_id'   => self::mixedToString(data_get($arLocationData, 'id', '')),
            'name'               => self::mixedToString(data_get($arLocationData, 'name', '')),
            'street_name'        => $sStreet,
            'street_number'      => '',
            'postal_code'        => self::mixedToString(data_get($arComponents, 'postalCode', '')),
            'city'               => self::mixedToString(data_get($arComponents, 'postalArea', '')),
            'country_code'       => $sCountry !== '' ? $sCountry : 'NO',
            'northing'           => is_numeric($mLat) ? (float) $mLat : null,
            'easting'            => is_numeric($mLng) ? (float) $mLng : null,
            'distance_in_meters' => is_numeric($mDistance) ? (int) $mDistance : null,
        ];
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
}
