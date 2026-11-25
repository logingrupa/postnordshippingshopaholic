<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Illuminate\Support\Facades\Lang;

/**
 * Class PostNordOrderValidator
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Validates that checkout form fields submitted during order creation match
 * the active server-side PostNord dynamic quote (product code, service point ID,
 * and postal code) so billed shipping and exported 1C fulfillment cannot diverge.
 */
class PostNordOrderValidator
{
    private const DEFAULT_ERROR = 'Please re-select a PostNord delivery option for your current postal code and cart.';

    /**
     * Verify that any PostNord checkout form fields present in the current request
     * match the active server quote.
     *
     * @param array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string} $arQuote
     */
    public static function matchesSubmittedCheckoutFields(array $arQuote): bool
    {
        return self::matchesProductCode($arQuote)
            && self::matchesServicePointId($arQuote)
            && self::matchesPostalCode($arQuote);
    }

    /**
     * Resolve the localized validation error message when a dynamic quote is missing or stale.
     */
    public static function getExpiredQuoteMessage(): string
    {
        $sKey = 'logingrupa.postnordshippingshopaholic::lang.component.quote_expired_error';
        try {
            $mTranslated = Lang::get($sKey);

            return is_string($mTranslated) && $mTranslated !== $sKey ? $mTranslated : self::DEFAULT_ERROR;
        } catch (\Throwable) {
            return self::DEFAULT_ERROR;
        }
    }

    /**
     * @param array{product_code: string} $arQuote
     */
    private static function matchesProductCode(array $arQuote): bool
    {
        $sSubmitted = strtoupper(self::readOrderInput('postnord_product_code'));

        return $sSubmitted === ''
            || $arQuote['product_code'] === ''
            || $sSubmitted === strtoupper($arQuote['product_code']);
    }

    /**
     * @param array{service_point_id: string} $arQuote
     */
    private static function matchesServicePointId(array $arQuote): bool
    {
        $sSubmitted = self::readOrderInput('postnord_service_point_id');
        if ($sSubmitted === '') {
            return true;
        }

        return !str_starts_with($arQuote['service_point_id'], 'product:')
            && $sSubmitted === $arQuote['service_point_id'];
    }

    /**
     * @param array{service_point_id: string, postal_code: string} $arQuote
     */
    private static function matchesPostalCode(array $arQuote): bool
    {
        $sSubmitted = self::normalizePostal(self::readOrderInput('postnord_postal_code'));
        if ($sSubmitted === '' && str_starts_with($arQuote['service_point_id'], 'product:')) {
            $sSubmitted = self::normalizePostal(self::readOrderInput('shipping_postcode'));
        }

        return $sSubmitted === '' || $arQuote['postal_code'] === '' || $sSubmitted === $arQuote['postal_code'];
    }

    /**
     * Read a checkout field from top-level input or Shopaholic's order_data/shipping_address payload.
     */
    private static function readOrderInput(string $sField): string
    {
        $arCandidates = [
            $sField,
            'order_data.property.' . $sField,
            'shipping_address.' . str_replace('shipping_', '', $sField),
        ];

        foreach ($arCandidates as $sKey) {
            $mValue = input($sKey);
            if (is_string($mValue) && trim($mValue) !== '') {
                return trim($mValue);
            }
        }

        return '';
    }

    /**
     * Normalize a postal code for comparison.
     */
    private static function normalizePostal(string $sRaw): string
    {
        $sCleaned = preg_replace('/^(?:NO|SE|DK|FI|LV|LT)[\s-]*/i', '', trim($sRaw));

        return (string) preg_replace('/\s+/', '', (string) $sCleaned);
    }
}
