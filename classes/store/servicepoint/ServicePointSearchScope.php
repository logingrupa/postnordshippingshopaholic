<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint;

use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordParcelCalculator;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordPriceCalculator;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;

/**
 * Class ServicePointSearchScope
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint
 *
 * Extracts scoped cache parameters and validates/normalizes PostNord service point
 * payloads before database persistence.
 */
class ServicePointSearchScope
{
    /**
     * Extract cache-isolation parameters from a PostNord ShippingType item.
     *
     * @param array{weight: int, length: int|null, width: int|null, height: int|null, cart_total_nok?: float|null, parcel_letter_max_price?: float|null, packaging?: string}|null $arParcel
     * @return array{shipping_type_id: int, api_mode: string, sandbox: string, country_code: string, max_results: int, postal_code: string, weight_grams: int, product_filter: string, parcel_sig: string}
     */
    public static function fromShippingType(
        ShippingTypeItem $obShippingTypeItem,
        string $sPostalCode,
        ?int $iWeightGrams = null,
        ?array $arParcel = null,
    ): array {
        /** @var mixed $mProperty */
        $mProperty = $obShippingTypeItem->property;
        $arProperty = is_array($mProperty) ? $mProperty : [];

        $bIsNorway = self::isNorwayMode($arProperty);
        $sCountryCode = self::resolveCountryCode($arProperty, $bIsNorway);
        $iMaxResults = self::resolveMaxResults($arProperty);
        $bIsSandbox = filter_var($arProperty['postnord_sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);

        /** @var mixed $mItemId */
        $mItemId = $obShippingTypeItem->id;
        $iShippingTypeId = is_numeric($mItemId) ? (int) $mItemId : 0;
        $iResolvedWeight = self::resolveWeightGrams($bIsNorway, $arParcel !== null ? $arParcel['weight'] : $iWeightGrams);
        $sParcelSig = $bIsNorway && $arParcel !== null
            ? PostNordParcelCalculator::buildParcelSignature($arParcel)
            : (string) $iResolvedWeight;

        return [
            'shipping_type_id' => $iShippingTypeId,
            'api_mode'         => $bIsNorway ? PostNordClient::MODE_NORWAY : PostNordClient::MODE_NORDIC,
            'sandbox'          => $bIsSandbox ? 'sandbox' : 'prod',
            'country_code'     => $sCountryCode,
            'max_results'      => $iMaxResults,
            'postal_code'      => $sPostalCode,
            'weight_grams'     => $iResolvedWeight,
            'product_filter'   => self::resolveProductFilter($arProperty, $bIsNorway),
            'parcel_sig'       => $sParcelSig,
        ];
    }

    /**
     * Build a deterministic, collision-free cache key for the given search scope.
     *
     * @param array{shipping_type_id: int, api_mode: string, sandbox: string, country_code: string, max_results: int, postal_code: string, weight_grams?: int, product_filter?: string, parcel_sig?: string} $arScope
     */
    public static function buildCacheKey(array $arScope): string
    {
        $sSignature = implode('|', [
            (string) $arScope['shipping_type_id'],
            $arScope['api_mode'],
            $arScope['sandbox'],
            $arScope['country_code'],
            (string) $arScope['max_results'],
            $arScope['postal_code'],
            (string) ($arScope['weight_grams'] ?? 0),
            (string) ($arScope['product_filter'] ?? 'all'),
            (string) ($arScope['parcel_sig'] ?? ''),
        ]);

        return 'postnord_sp_v4_' . hash('sha256', $sSignature);
    }

    /**
     * Validate required ServicePoint fields and normalize country_code.
     *
     * @param array<string, mixed> $arRaw
     * @return array<string, mixed>|null
     */
    public static function normalizePointAttributes(array $arRaw, string $sFallbackCountry): ?array
    {
        $sPointId = trim(self::mixedToString($arRaw['service_point_id'] ?? ''));
        $sName = trim(self::mixedToString($arRaw['name'] ?? ''));
        $sPostalCode = trim(self::mixedToString($arRaw['postal_code'] ?? ''));
        $sCity = trim(self::mixedToString($arRaw['city'] ?? ''));

        if ($sPointId === '' || $sName === '' || $sPostalCode === '' || $sCity === '') {
            return null;
        }

        $sRawCountry = strtoupper(trim(self::mixedToString($arRaw['country_code'] ?? '')));
        $sCountryCode = strlen($sRawCountry) === 2 ? $sRawCountry : $sFallbackCountry;
        if (strlen($sCountryCode) !== 2) {
            return null;
        }

        return [
            'service_point_id' => $sPointId,
            'name'             => $sName,
            'street_name'      => trim(self::mixedToString($arRaw['street_name'] ?? '')),
            'street_number'    => trim(self::mixedToString($arRaw['street_number'] ?? '')),
            'postal_code'      => $sPostalCode,
            'city'             => $sCity,
            'country_code'     => $sCountryCode,
            'northing'         => is_numeric($arRaw['northing'] ?? null) ? (float) $arRaw['northing'] : null,
            'easting'          => is_numeric($arRaw['easting'] ?? null) ? (float) $arRaw['easting'] : null,
        ];
    }

    /**
     * Determine whether the property array targets PostNord Norway OAuth2 mode.
     *
     * @param array<mixed, mixed> $arProperty
     */
    private static function isNorwayMode(array $arProperty): bool
    {
        $sRawMode = trim(self::mixedToString($arProperty['postnord_api_mode'] ?? ''));
        if ($sRawMode === PostNordClient::MODE_NORWAY) {
            return true;
        }

        $sClientId = trim(self::mixedToString($arProperty['postnord_client_id'] ?? ''));
        $sApiKey = trim(self::mixedToString($arProperty['postnord_api_key'] ?? ''));

        return $sRawMode === '' && $sClientId !== '' && $sApiKey === '';
    }

    /**
     * Resolve effective ISO 2-letter country code for the scope.
     *
     * @param array<mixed, mixed> $arProperty
     */
    private static function resolveCountryCode(array $arProperty, bool $bIsNorway): string
    {
        if ($bIsNorway) {
            return 'NO';
        }

        $sRawCountry = strtoupper(trim(self::mixedToString($arProperty['postnord_country_code'] ?? 'NO')));

        return strlen($sRawCountry) === 2 ? $sRawCountry : 'NO';
    }

    /**
     * Resolve maximum result count for the scope.
     *
     * @param array<mixed, mixed> $arProperty
     */
    private static function resolveMaxResults(array $arProperty): int
    {
        $mMaxResults = $arProperty['postnord_max_results'] ?? 10;

        return is_numeric($mMaxResults) ? max(1, (int) $mMaxResults) : 10;
    }

    /**
     * Resolve effective weight in grams for cache key isolation.
     */
    private static function resolveWeightGrams(bool $bIsNorway, ?int $iWeightGrams): int
    {
        return $bIsNorway && $iWeightGrams !== null && $iWeightGrams > 0 ? $iWeightGrams : 0;
    }

    /**
     * Resolve effective product filter name for cache key isolation.
     *
     * @param array<mixed, mixed> $arProperty
     */
    private static function resolveProductFilter(array $arProperty, bool $bIsNorway): string
    {
        if (!$bIsNorway) {
            return 'all';
        }

        return implode(',', PostNordPriceCalculator::resolveEnabledProductCodes($arProperty));
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
