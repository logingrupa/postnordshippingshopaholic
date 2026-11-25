<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

/**
 * Class PostNordNorwayResponseParser
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Parses PostNord Norway B2C Products API responses into normalized
 * service point arrays carrying live net pricing, and delegates delivery
 * product option parsing to PostNordNorwayProductParser.
 */
class PostNordNorwayResponseParser
{
    /**
     * Parse both selectable delivery product options (S, Z, B, H, Q) and
     * distance-sorted pickup points from a single /rest/api/products response.
     *
     * @param array<mixed, mixed> $arResponseData Raw /rest/api/products JSON
     * @param int $iMaxResults Maximum number of pickup points to return
     * @param list<string> $arAllowedCodes Optional productCode whitelist (empty = all)
     * @return array{products: list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}>, points: array<int, array<string, mixed>>}
     */
    public static function parseDeliveryCatalog(
        array $arResponseData,
        int $iMaxResults,
        array $arAllowedCodes = [],
    ): array {
        $arPoints = self::parseProducts($arResponseData, $iMaxResults, $arAllowedCodes);
        $arProducts = PostNordNorwayProductParser::parseProductOptions($arResponseData, $arAllowedCodes);

        /** @var array<string, bool> $arPointCodes */
        $arPointCodes = [];
        foreach ($arPoints as $arPoint) {
            $sCode = strtoupper(trim(self::mixedToString($arPoint['product_code'] ?? '')));
            if ($sCode !== '') {
                $arPointCodes[$sCode] = true;
            }
        }

        $arFilteredProducts = array_values(array_filter(
            $arProducts,
            static fn (array $arProduct): bool => !$arProduct['needs_pickup_point']
                || isset($arPointCodes[$arProduct['product_code']])
        ));

        return [
            'products' => $arFilteredProducts,
            'points'   => $arPoints,
        ];
    }

    /**
     * Summarize the lowest live net price found in a parsed point list for testConnection().
     *
     * @param array<int, array<string, mixed>> $arPointList
     */
    public static function formatTestPriceSummary(array $arPointList): string
    {
        return PostNordNorwayProductParser::formatTestPriceSummary($arPointList);
    }

    /**
     * Extract, deduplicate, and distance-sort serviceLocations across products.
     *
     * @param array<mixed, mixed> $arResponseData Raw /rest/api/products JSON
     * @param int $iMaxResults Maximum number of points to return
     * @param list<string> $arAllowedCodes Optional productCode whitelist (empty = all)
     * @return array<int, array<string, mixed>> Normalized service point records
     */
    public static function parseProducts(
        array $arResponseData,
        int $iMaxResults,
        array $arAllowedCodes = [],
    ): array {
        $mProductList = data_get($arResponseData, 'products');
        if (!is_array($mProductList) || $mProductList === []) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $arById */
        $arById = [];

        foreach ($mProductList as $mProduct) {
            self::collectProductLocations($mProduct, $arById, $arAllowedCodes);
        }

        $arResultList = array_values($arById);
        usort($arResultList, static function (array $arFirst, array $arSecond): int {
            $iFirst = is_int($arFirst['distance_in_meters'] ?? null) ? $arFirst['distance_in_meters'] : PHP_INT_MAX;
            $iSecond = is_int($arSecond['distance_in_meters'] ?? null) ? $arSecond['distance_in_meters'] : PHP_INT_MAX;

            return $iFirst <=> $iSecond;
        });

        return self::slicePerProductCode($arResultList, max(1, $iMaxResults));
    }

    /**
     * Keep up to $iMaxPerProduct nearest pickup points per product code (e.g. S and Z)
     * so locker-dense postal codes never starve Service Point (Z) out of the catalog.
     *
     * @param array<int, array<string, mixed>> $arSortedList
     * @return array<int, array<string, mixed>>
     */
    private static function slicePerProductCode(array $arSortedList, int $iMaxPerProduct): array
    {
        /** @var array<string, int> $arCountByCode */
        $arCountByCode = [];
        /** @var array<int, array<string, mixed>> $arKept */
        $arKept = [];

        foreach ($arSortedList as $arPoint) {
            $sCode = strtoupper(trim(self::mixedToString($arPoint['product_code'] ?? '')));
            $iSeen = $arCountByCode[$sCode] ?? 0;
            if ($iSeen >= $iMaxPerProduct) {
                continue;
            }
            $arCountByCode[$sCode] = $iSeen + 1;
            $arKept[] = $arPoint;
        }

        return $arKept;
    }

    /**
     * Collect normalized serviceLocations from a single product entry into the ID map.
     *
     * @param mixed $mProduct Raw product entry
     * @param array<string, array<string, mixed>> $arById Deduplicated output map
     * @param list<string> $arAllowedCodes Optional productCode whitelist
     */
    private static function collectProductLocations(mixed $mProduct, array &$arById, array $arAllowedCodes): void
    {
        if (!is_array($mProduct)) {
            return;
        }

        $mLocationList = data_get($mProduct, 'serviceLocations');
        if (!is_array($mLocationList)) {
            return;
        }

        $arProductPricing = self::extractProductPricing($mProduct);
        if (!self::isProductCodeAllowed($arProductPricing['product_code'], $arAllowedCodes)) {
            return;
        }

        foreach ($mLocationList as $mLocation) {
            if (is_array($mLocation)) {
                self::mergeParsedLocation($mLocation, $arProductPricing, $arById);
            }
        }
    }

    /**
     * Check whether a product code passes the optional whitelist.
     *
     * @param list<string> $arAllowedCodes
     */
    private static function isProductCodeAllowed(string $sProductCode, array $arAllowedCodes): bool
    {
        $sCode = strtoupper($sProductCode);

        return $arAllowedCodes === [] || $sCode === '' || in_array($sCode, $arAllowedCodes, true);
    }

    /**
     * Parse and insert a single location into the deduplicated ID map, preferring lower net price.
     *
     * @param array<mixed, mixed> $arLocation
     * @param array{product_code: string, price_net: float|null, price_currency: string} $arProductPricing
     * @param array<string, array<string, mixed>> $arById
     */
    private static function mergeParsedLocation(array $arLocation, array $arProductPricing, array &$arById): void
    {
        $arParsed = self::parseOneLocation($arLocation, $arProductPricing);
        $sPointId = self::mixedToString($arParsed['service_point_id'] ?? '');
        if ($sPointId === '') {
            return;
        }

        if (!isset($arById[$sPointId]) || self::isCheaperThan($arParsed, $arById[$sPointId])) {
            $arById[$sPointId] = $arParsed;
        }
    }

    /**
     * Extract product code and live net price from a product object.
     *
     * @param array<mixed, mixed> $arProduct
     * @return array{product_code: string, price_net: float|null, price_currency: string}
     */
    private static function extractProductPricing(array $arProduct): array
    {
        $sProductCode = trim(self::mixedToString(data_get($arProduct, 'productCode', '')));
        $mNetValue = data_get($arProduct, 'price.priceBreakdown.netAmount.value');
        $fNetPrice = is_numeric($mNetValue) ? (float) $mNetValue : null;
        $sCurrency = strtoupper(trim(self::mixedToString(
            data_get($arProduct, 'price.priceBreakdown.netAmount.currency', 'NOK')
        )));

        return [
            'product_code'   => $sProductCode,
            'price_net'      => $fNetPrice,
            'price_currency' => $sCurrency !== '' ? $sCurrency : 'NOK',
        ];
    }

    /**
     * Prefer a newly parsed location over an existing duplicate when it has a
     * lower positive live net price.
     *
     * @param array<string, mixed> $arCandidate
     * @param array<string, mixed> $arExisting
     */
    private static function isCheaperThan(array $arCandidate, array $arExisting): bool
    {
        $mCandidateNet = $arCandidate['price_net'] ?? null;
        if (!is_numeric($mCandidateNet)) {
            return false;
        }

        $mExistingNet = $arExisting['price_net'] ?? null;
        if (!is_numeric($mExistingNet)) {
            return true;
        }

        return (float) $mCandidateNet < (float) $mExistingNet;
    }

    /**
     * Parse one serviceLocation entry into the ServicePoint attribute shape.
     *
     * @param array<mixed, mixed> $arLocationData Single serviceLocation object
     * @param array{product_code: string, price_net: float|null, price_currency: string} $arProductPricing
     * @return array<string, mixed> Normalized service point record
     */
    private static function parseOneLocation(array $arLocationData, array $arProductPricing): array
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
            'product_code'       => $arProductPricing['product_code'],
            'price_net'          => $arProductPricing['price_net'],
            'price_currency'     => $arProductPricing['price_currency'],
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

        return is_int($mValue) || is_float($mValue) ? (string) $mValue : '';
    }
}
