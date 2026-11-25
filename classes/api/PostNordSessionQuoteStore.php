<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Illuminate\Support\Facades\Session;

/**
 * Class PostNordSessionQuoteStore
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Tamper-proof server-side session storage for PostNord Norway live dynamic
 * shipping quotes. Point/product prices and authoritative carrier metadata
 * (product code, product name, service point details, postal code) are stored
 * on the server during lookup and validated on selection and order creation.
 */
class PostNordSessionQuoteStore
{
    public const SESSION_QUOTES_KEY = 'postnord_point_quotes';
    public const SESSION_SELECTED_QUOTE_KEY = 'postnord_selected_quote';

    /**
     * Persist server-computed point and product quotes for a shipping type in session.
     *
     * @param array<string, array{price_default: float, price_net: float, currency: string, product_code: string, product_name?: string, service_point_name?: string, service_point_address?: string}> $arQuoteMap
     */
    public static function storePointQuotes(
        int $iShippingTypeId,
        string $sPostalCode,
        int $iWeightGrams,
        array $arQuoteMap,
        string $sParcelSig = '',
    ): void {
        if ($iShippingTypeId <= 0 || $arQuoteMap === []) {
            return;
        }

        $mExisting = Session::get(self::SESSION_QUOTES_KEY, []);
        $arAllQuotes = is_array($mExisting) ? $mExisting : [];
        $arAllQuotes[(string) $iShippingTypeId] = [
            'postal_code'  => $sPostalCode,
            'weight_grams' => $iWeightGrams,
            'parcel_sig'   => $sParcelSig,
            'points'       => $arQuoteMap,
        ];

        Session::put(self::SESSION_QUOTES_KEY, $arAllQuotes);
    }

    /**
     * Select a service point or product quote from the server-stored bucket and
     * save it as the active PostNord shipping quote in session. When an expected
     * postal code is supplied, it must match the bucket's postal_code.
     *
     * @return array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}|null
     */
    public static function selectPointQuote(
        int $iShippingTypeId,
        string $sServicePointId,
        string $sExpectedPostalCode = '',
    ): ?array {
        $arTypeBucket = self::readShippingTypeBucket($iShippingTypeId);
        if ($arTypeBucket === null || $sServicePointId === '') {
            return null;
        }

        $sBucketPostal = self::mixedToString($arTypeBucket['postal_code'] ?? '');
        if ($sExpectedPostalCode !== '' && $sBucketPostal !== '' && $sBucketPostal !== $sExpectedPostalCode) {
            return null;
        }

        $arPointQuote = self::readPointFromBucket($arTypeBucket, $sServicePointId);
        if ($arPointQuote === null) {
            return null;
        }

        $arSelected = self::normalizeSelectedQuote($iShippingTypeId, $sServicePointId, $arTypeBucket, $arPointQuote);
        Session::put(self::SESSION_SELECTED_QUOTE_KEY, $arSelected);

        return $arSelected;
    }

    /**
     * Read the active server-stored shipping quote for a ShippingType, if any.
     *
     * @return array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}|null
     */
    public static function getActiveSelectedQuote(int $iShippingTypeId): ?array
    {
        if ($iShippingTypeId <= 0) {
            return null;
        }

        $mSelected = Session::get(self::SESSION_SELECTED_QUOTE_KEY);
        if (!is_array($mSelected)) {
            return null;
        }

        return self::parseStoredSelectedQuote($mSelected, $iShippingTypeId);
    }

    /**
     * Clear only the active selected quote if it belongs to the given shipping type.
     */
    public static function clearSelectedQuote(int $iShippingTypeId): void
    {
        if (self::getActiveSelectedQuote($iShippingTypeId) !== null) {
            Session::forget(self::SESSION_SELECTED_QUOTE_KEY);
        }
    }

    /**
     * Clear any stored dynamic pricing quotes from the session.
     */
    public static function clearSessionQuotes(): void
    {
        Session::forget(self::SESSION_QUOTES_KEY);
        Session::forget(self::SESSION_SELECTED_QUOTE_KEY);
    }

    /**
     * Read the quote bucket for a specific shipping type from session.
     *
     * @return array<mixed, mixed>|null
     */
    private static function readShippingTypeBucket(int $iShippingTypeId): ?array
    {
        if ($iShippingTypeId <= 0) {
            return null;
        }

        $mAllQuotes = Session::get(self::SESSION_QUOTES_KEY, []);
        if (!is_array($mAllQuotes)) {
            return null;
        }

        $mBucket = $mAllQuotes[(string) $iShippingTypeId] ?? null;

        return is_array($mBucket) ? $mBucket : null;
    }

    /**
     * Extract a validated point quote entry from a shipping type bucket.
     *
     * @param array<mixed, mixed> $arTypeBucket
     * @return array<mixed, mixed>|null
     */
    private static function readPointFromBucket(array $arTypeBucket, string $sServicePointId): ?array
    {
        $mPoints = $arTypeBucket['points'] ?? null;
        if (!is_array($mPoints)) {
            return null;
        }

        $mPointQuote = $mPoints[$sServicePointId] ?? null;
        if (!is_array($mPointQuote) || !is_numeric($mPointQuote['price_default'] ?? null)) {
            return null;
        }

        return $mPointQuote;
    }

    /**
     * Build a normalized active quote record from bucket and point data.
     *
     * @param array<mixed, mixed> $arTypeBucket
     * @param array<mixed, mixed> $arPointQuote
     * @return array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}
     */
    private static function normalizeSelectedQuote(
        int $iShippingTypeId,
        string $sServicePointId,
        array $arTypeBucket,
        array $arPointQuote,
    ): array {
        return [
            'shipping_type_id'      => $iShippingTypeId,
            'service_point_id'      => $sServicePointId,
            'postal_code'           => self::mixedToString($arTypeBucket['postal_code'] ?? ''),
            'weight_grams'          => self::mixedToInt($arTypeBucket['weight_grams'] ?? 0),
            'parcel_sig'            => self::mixedToString($arTypeBucket['parcel_sig'] ?? ''),
            'price_default'         => self::mixedToFloat($arPointQuote['price_default'] ?? 0.0),
            'price_net'             => self::mixedToFloat($arPointQuote['price_net'] ?? 0.0),
            'currency'              => self::mixedToString($arPointQuote['currency'] ?? 'NOK'),
            'product_code'          => self::mixedToString($arPointQuote['product_code'] ?? ''),
            'product_name'          => self::mixedToString($arPointQuote['product_name'] ?? ''),
            'service_point_name'    => self::mixedToString($arPointQuote['service_point_name'] ?? ''),
            'service_point_address' => self::mixedToString($arPointQuote['service_point_address'] ?? ''),
        ];
    }

    /**
     * Validate and normalize a stored selected-quote array for the requested shipping type.
     *
     * @param array<mixed, mixed> $arSelected
     * @return array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}|null
     */
    private static function parseStoredSelectedQuote(array $arSelected, int $iShippingTypeId): ?array
    {
        $mQuoteTypeId = $arSelected['shipping_type_id'] ?? null;
        $mPriceDefault = $arSelected['price_default'] ?? null;
        if (!is_numeric($mQuoteTypeId) || (int) $mQuoteTypeId !== $iShippingTypeId || !is_numeric($mPriceDefault)) {
            return null;
        }

        return [
            'shipping_type_id'      => (int) $mQuoteTypeId,
            'service_point_id'      => self::mixedToString($arSelected['service_point_id'] ?? ''),
            'postal_code'           => self::mixedToString($arSelected['postal_code'] ?? ''),
            'weight_grams'          => self::mixedToInt($arSelected['weight_grams'] ?? 0),
            'parcel_sig'            => self::mixedToString($arSelected['parcel_sig'] ?? ''),
            'price_default'         => (float) $mPriceDefault,
            'price_net'             => self::mixedToFloat($arSelected['price_net'] ?? 0.0),
            'currency'              => self::mixedToString($arSelected['currency'] ?? 'NOK'),
            'product_code'          => self::mixedToString($arSelected['product_code'] ?? ''),
            'product_name'          => self::mixedToString($arSelected['product_name'] ?? ''),
            'service_point_name'    => self::mixedToString($arSelected['service_point_name'] ?? ''),
            'service_point_address' => self::mixedToString($arSelected['service_point_address'] ?? ''),
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

    /**
     * Safely convert mixed value to int.
     */
    private static function mixedToInt(mixed $mValue): int
    {
        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    /**
     * Safely convert mixed value to float.
     */
    private static function mixedToFloat(mixed $mValue): float
    {
        return is_numeric($mValue) ? (float) $mValue : 0.0;
    }
}
