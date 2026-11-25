<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint;

/**
 * Class ServicePointCacheNormalizer
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint
 *
 * Defensively normalizes cached PostNord delivery catalog payloads (delivery
 * product options and pickup point entries) read from Laravel Cache.
 */
class ServicePointCacheNormalizer
{
    /**
     * Build a normalized point cache entry from persisted ID and API metadata.
     *
     * @param array<mixed, mixed> $arData
     * @return array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}
     */
    public static function buildPointCacheEntry(int $iModelId, array $arData): array
    {
        $mDistance = $arData['distance_in_meters'] ?? null;
        $mPriceNet = $arData['price_net'] ?? null;
        $sCurrency = strtoupper(trim(self::mixedToString($arData['price_currency'] ?? 'NOK')));

        return [
            'id'                 => $iModelId,
            'distance_in_meters' => is_numeric($mDistance) ? (int) $mDistance : null,
            'price_net'          => is_numeric($mPriceNet) ? (float) $mPriceNet : null,
            'price_currency'     => $sCurrency !== '' ? $sCurrency : 'NOK',
            'product_code'       => trim(self::mixedToString($arData['product_code'] ?? '')),
        ];
    }

    /**
     * Normalize a cached delivery catalog payload defensively.
     *
     * @param array<mixed, mixed> $arCached
     * @return array{products: list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}>, points: list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}>}
     */
    public static function normalizeCachedCatalog(array $arCached): array
    {
        $mProducts = $arCached['products'] ?? [];
        $mPoints = $arCached['points'] ?? [];

        return [
            'products' => is_array($mProducts) ? self::normalizeCachedProducts($mProducts) : [],
            'points'   => is_array($mPoints) ? self::normalizeCachedPoints($mPoints) : [],
        ];
    }

    /**
     * Normalize cached delivery product entries defensively.
     *
     * @param array<mixed, mixed> $arCached
     * @return list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}>
     */
    private static function normalizeCachedProducts(array $arCached): array
    {
        /** @var list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}> $arProducts */
        $arProducts = [];

        foreach ($arCached as $mProduct) {
            $arOne = is_array($mProduct) ? self::normalizeOneCachedProduct($mProduct) : null;
            if ($arOne !== null) {
                $arProducts[] = $arOne;
            }
        }

        return $arProducts;
    }

    /**
     * Normalize a single cached product entry.
     *
     * @param array<mixed, mixed> $arProduct
     * @return array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}|null
     */
    private static function normalizeOneCachedProduct(array $arProduct): ?array
    {
        $sCode = trim(self::mixedToString($arProduct['product_code'] ?? ''));
        if ($sCode === '') {
            return null;
        }

        $mNet = $arProduct['price_net'] ?? null;
        $mDays = $arProduct['working_days'] ?? null;
        $sCurrency = strtoupper(trim(self::mixedToString($arProduct['price_currency'] ?? 'NOK')));
        $sDisplayName = trim(self::mixedToString($arProduct['display_name'] ?? ''));

        return [
            'product_code'       => $sCode,
            'display_name'       => $sDisplayName !== '' ? $sDisplayName : $sCode,
            'description'        => self::mixedToString($arProduct['description'] ?? ''),
            'tip'                => self::mixedToString($arProduct['tip'] ?? ''),
            'price_net'          => is_numeric($mNet) ? (float) $mNet : null,
            'price_currency'     => $sCurrency !== '' ? $sCurrency : 'NOK',
            'working_days'       => is_numeric($mDays) ? (int) $mDays : null,
            'needs_pickup_point' => (bool) ($arProduct['needs_pickup_point'] ?? false),
        ];
    }

    /**
     * Normalize cached point entries defensively.
     *
     * @param array<mixed, mixed> $arCached
     * @return list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}>
     */
    private static function normalizeCachedPoints(array $arCached): array
    {
        /** @var list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}> $arEntries */
        $arEntries = [];

        foreach ($arCached as $mEntry) {
            if (is_array($mEntry) && is_numeric($mEntry['id'] ?? null)) {
                $arEntries[] = self::buildPointCacheEntry((int) $mEntry['id'], $mEntry);
            }
        }

        return $arEntries;
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
