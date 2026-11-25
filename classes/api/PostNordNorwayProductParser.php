<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

/**
 * Class PostNordNorwayProductParser
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Parses selectable PostNord Norway delivery product options (Parcel Locker S,
 * Service Point Z, Home Small B, Home H, Home Attended Q), resolves enabled
 * product filters, and formats testConnection pricing summaries.
 */
class PostNordNorwayProductParser
{
    /**
     * Resolve enabled PostNord Norway product codes (S, Z, B, H, Q).
     *
     * @param array<mixed, mixed> $arProperty
     * @return list<string>
     */
    public static function resolveEnabledProductCodes(array $arProperty): array
    {
        $mRawList = $arProperty['postnord_enabled_products'] ?? null;
        if (is_array($mRawList) && $mRawList !== []) {
            $arValid = self::filterValidProductCodes($mRawList);
            if ($arValid !== []) {
                return $arValid;
            }
        }

        $sFilter = trim(self::mixedToString($arProperty['postnord_product_filter'] ?? PostNordPriceCalculator::PRODUCT_ALL));

        return match ($sFilter) {
            PostNordPriceCalculator::PRODUCT_LOCKER        => [PostNordPriceCalculator::CODE_LOCKER],
            PostNordPriceCalculator::PRODUCT_SERVICE_POINT => [PostNordPriceCalculator::CODE_SERVICE_POINT],
            default                                        => PostNordPriceCalculator::DEFAULT_ENABLED_CODES,
        };
    }

    /**
     * Resolve the PostNord Norway settings.products filter list for /rest/api/products.
     *
     * @param array<mixed, mixed> $arProperty
     * @return list<string>
     */
    public static function resolveProductFilterList(array $arProperty): array
    {
        $arCodes = self::resolveEnabledProductCodes($arProperty);
        $arHomeCodes = [
            PostNordPriceCalculator::CODE_HOME_SMALL,
            PostNordPriceCalculator::CODE_HOME,
            PostNordPriceCalculator::CODE_HOME_ATTENDED,
        ];

        if (array_intersect($arCodes, $arHomeCodes) !== []) {
            return [];
        }

        $arApiProducts = [];
        if (in_array(PostNordPriceCalculator::CODE_LOCKER, $arCodes, true)) {
            $arApiProducts[] = PostNordPriceCalculator::PRODUCT_LOCKER;
        }
        if (in_array(PostNordPriceCalculator::CODE_SERVICE_POINT, $arCodes, true)) {
            $arApiProducts[] = PostNordPriceCalculator::PRODUCT_SERVICE_POINT;
        }

        return $arApiProducts !== []
            ? $arApiProducts
            : [PostNordPriceCalculator::PRODUCT_LOCKER, PostNordPriceCalculator::PRODUCT_SERVICE_POINT];
    }

    /**
     * Extract available delivery product options sorted cheapest-first.
     *
     * @param array<mixed, mixed> $arResponseData Raw /rest/api/products JSON
     * @param list<string> $arAllowedCodes Optional productCode whitelist (empty = all)
     * @return list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}>
     */
    public static function parseProductOptions(array $arResponseData, array $arAllowedCodes = []): array
    {
        $mProductList = data_get($arResponseData, 'products');
        if (!is_array($mProductList) || $mProductList === []) {
            return [];
        }

        /** @var array<string, array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}> $arByCode */
        $arByCode = [];

        foreach ($mProductList as $mProduct) {
            if (!is_array($mProduct)) {
                continue;
            }

            $arOption = self::parseOneProductOption($mProduct, $arAllowedCodes);
            if ($arOption !== null) {
                $arByCode[$arOption['product_code']] = $arOption;
            }
        }

        $arOptions = array_values($arByCode);
        usort($arOptions, static function (array $arFirst, array $arSecond): int {
            $fFirst = $arFirst['price_net'] ?? PHP_FLOAT_MAX;
            $fSecond = $arSecond['price_net'] ?? PHP_FLOAT_MAX;

            return $fFirst <=> $fSecond;
        });

        return $arOptions;
    }

    /**
     * Summarize the lowest live net price found in a parsed point list for testConnection().
     *
     * @param array<int, array<string, mixed>> $arPointList
     */
    public static function formatTestPriceSummary(array $arPointList): string
    {
        $fLowest = null;
        $sCurrency = 'NOK';

        foreach ($arPointList as $arPoint) {
            $mNet = $arPoint['price_net'] ?? null;
            if (!is_numeric($mNet) || (float) $mNet <= 0.0) {
                continue;
            }

            $fNet = (float) $mNet;
            if ($fLowest === null || $fNet < $fLowest) {
                $fLowest = $fNet;
                $sCandidate = self::mixedToString($arPoint['price_currency'] ?? 'NOK');
                $sCurrency = $sCandidate !== '' ? $sCandidate : 'NOK';
            }
        }

        return $fLowest !== null
            ? '; live pricing active (1 kg from ' . $fLowest . ' ' . $sCurrency . ' net)'
            : '';
    }

    /**
     * Parse one product entry into a selectable delivery option record.
     *
     * @param array<mixed, mixed> $arProduct
     * @param list<string> $arAllowedCodes
     * @return array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}|null
     */
    private static function parseOneProductOption(array $arProduct, array $arAllowedCodes): ?array
    {
        $sCode = strtoupper(trim(self::mixedToString(data_get($arProduct, 'productCode', ''))));
        if (!self::isCodeAllowed($sCode, $arAllowedCodes)) {
            return null;
        }

        $bHasLocations = self::hasServiceLocations($arProduct);
        $bIsPickupProduct = self::isPickupProductCode($sCode);
        if ($bIsPickupProduct && !$bHasLocations) {
            return null;
        }

        $mNet = data_get($arProduct, 'price.priceBreakdown.netAmount.value');
        $sCurrency = strtoupper(trim(self::mixedToString(data_get($arProduct, 'price.priceBreakdown.netAmount.currency', 'NOK'))));
        $mDays = data_get($arProduct, 'expectedDelivery.workingDays');

        return [
            'product_code'       => $sCode,
            'display_name'       => trim(self::mixedToString(data_get($arProduct, 'displayName', $sCode))),
            'description'        => trim(self::mixedToString(data_get($arProduct, 'texts.descriptionText', ''))),
            'tip'                => trim(self::mixedToString(data_get($arProduct, 'texts.tip', ''))),
            'price_net'          => is_numeric($mNet) ? (float) $mNet : null,
            'price_currency'     => $sCurrency !== '' ? $sCurrency : 'NOK',
            'working_days'       => is_numeric($mDays) ? (int) $mDays : null,
            'needs_pickup_point' => $bIsPickupProduct || $bHasLocations,
        ];
    }

    /**
     * Check whether a product code is non-empty and included in the allowed whitelist.
     *
     * @param list<string> $arAllowedCodes
     */
    private static function isCodeAllowed(string $sCode, array $arAllowedCodes): bool
    {
        return $sCode !== '' && ($arAllowedCodes === [] || in_array($sCode, $arAllowedCodes, true));
    }

    /**
     * Check whether a product entry contains at least one pickup location.
     *
     * @param array<mixed, mixed> $arProduct
     */
    private static function hasServiceLocations(array $arProduct): bool
    {
        $mLocations = data_get($arProduct, 'serviceLocations');

        return is_array($mLocations) && $mLocations !== [];
    }

    /**
     * Check whether a product code is a pickup-point delivery type (S or Z).
     */
    private static function isPickupProductCode(string $sCode): bool
    {
        return in_array($sCode, [PostNordPriceCalculator::CODE_LOCKER, PostNordPriceCalculator::CODE_SERVICE_POINT], true);
    }

    /**
     * Normalize and validate a raw checkboxlist of product codes.
     *
     * @param array<mixed, mixed> $arRawList
     * @return list<string>
     */
    private static function filterValidProductCodes(array $arRawList): array
    {
        $arValid = [];
        foreach ($arRawList as $mCode) {
            $sCode = strtoupper(trim(self::mixedToString($mCode)));
            if (in_array($sCode, PostNordPriceCalculator::ALL_PRODUCT_CODES, true) && !in_array($sCode, $arValid, true)) {
                $arValid[] = $sCode;
            }
        }

        return $arValid;
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
