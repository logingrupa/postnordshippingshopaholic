<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Models\Currency;
use Lovata\Toolbox\Classes\Helper\PriceHelper;

/**
 * Class PostNordPriceCalculator
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Pure pricing, weight, product selection, rounding, and currency conversion
 * helpers for PostNord Norway live dynamic shipping rates.
 */
class PostNordPriceCalculator
{
    public const PRODUCT_ALL = 'all';
    public const PRODUCT_LOCKER = 'mypackbox';
    public const PRODUCT_SERVICE_POINT = 'mypack';

    public const CODE_LOCKER = 'S';
    public const CODE_SERVICE_POINT = 'Z';
    public const CODE_HOME_SMALL = 'B';
    public const CODE_HOME = 'H';
    public const CODE_HOME_ATTENDED = 'Q';

    /** @var list<string> */
    public const ALL_PRODUCT_CODES = [
        self::CODE_LOCKER,
        self::CODE_SERVICE_POINT,
        self::CODE_HOME_SMALL,
        self::CODE_HOME,
        self::CODE_HOME_ATTENDED,
    ];

    /** @var list<string> */
    public const DEFAULT_ENABLED_CODES = [
        self::CODE_LOCKER,
        self::CODE_SERVICE_POINT,
        self::CODE_HOME_SMALL,
        self::CODE_HOME,
    ];

    public const ROUND_NONE = 'none';
    public const ROUND_CEIL = 'ceil';
    public const ROUND_CEIL_5 = 'ceil_5';
    public const ROUND_CEIL_10 = 'ceil_10';

    public const MIN_WEIGHT_GRAMS = 100;
    public const DEFAULT_ITEM_WEIGHT_GRAMS = 500;
    public const DEFAULT_VAT_PERCENT = 25.0;

    /**
     * Determine whether live dynamic pricing is enabled for this ShippingType.
     *
     * @param array<mixed, mixed> $arProperty
     */
    public static function isDynamicPricingEnabled(array $arProperty): bool
    {
        if (!filter_var($arProperty['postnord_dynamic_pricing'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $sMode = trim(self::mixedToString($arProperty['postnord_api_mode'] ?? ''));
        $sClientId = trim(self::mixedToString($arProperty['postnord_client_id'] ?? ''));
        $sApiKey = trim(self::mixedToString($arProperty['postnord_api_key'] ?? ''));

        return $sMode === PostNordClient::MODE_NORWAY
            || ($sMode === '' && $sClientId !== '' && $sApiKey === '');
    }

    /**
     * Resolve enabled PostNord Norway product codes (S, Z, B, H, Q).
     *
     * @param array<mixed, mixed> $arProperty
     * @return list<string>
     */
    public static function resolveEnabledProductCodes(array $arProperty): array
    {
        return PostNordNorwayProductParser::resolveEnabledProductCodes($arProperty);
    }

    /**
     * Resolve the PostNord Norway settings.products filter list for the B2C Products API.
     *
     * @param array<mixed, mixed> $arProperty
     * @return list<string>
     */
    public static function resolveProductFilterList(array $arProperty): array
    {
        return PostNordNorwayProductParser::resolveProductFilterList($arProperty);
    }

    /**
     * Resolve the configured default fallback item weight in grams.
     *
     * @param array<mixed, mixed> $arProperty
     */
    public static function resolveDefaultWeightGrams(array $arProperty): int
    {
        $mRaw = $arProperty['postnord_default_weight_grams'] ?? self::DEFAULT_ITEM_WEIGHT_GRAMS;

        return is_numeric($mRaw) && (int) $mRaw > 0
            ? (int) $mRaw
            : self::DEFAULT_ITEM_WEIGHT_GRAMS;
    }

    /**
     * Calculate total parcel weight in grams from an iterable of cart positions.
     *
     * @param iterable<mixed> $obPositionList
     */
    public static function calculateCartWeightGrams(iterable $obPositionList, int $iDefaultItemWeightGrams = self::DEFAULT_ITEM_WEIGHT_GRAMS): int
    {
        $arMetrics = PostNordCartMeasurer::measureCartItems($obPositionList, max(1, $iDefaultItemWeightGrams));

        return max(self::MIN_WEIGHT_GRAMS, $arMetrics['weight_grams']);
    }

    /**
     * Calculate the current active Shopaholic cart weight in grams.
     *
     * @param array<mixed, mixed> $arProperty
     */
    public static function calculateCurrentCartWeightGrams(array $arProperty): int
    {
        $iDefaultWeight = self::resolveDefaultWeightGrams($arProperty);

        try {
            /** @var CartProcessor $obCartProcessor */
            $obCartProcessor = CartProcessor::instance();
            /** @var iterable<mixed> $obPositionList */
            $obPositionList = $obCartProcessor->get();

            return self::calculateCartWeightGrams($obPositionList, $iDefaultWeight);
        } catch (\Throwable) {
            return max(self::MIN_WEIGHT_GRAMS, $iDefaultWeight);
        }
    }

    /**
     * Apply configured VAT, fixed markup, and rounding to a PostNord net price.
     *
     * @param array<mixed, mixed> $arProperty
     */
    public static function calculateCustomerPrice(float $fNetPrice, array $arProperty): float
    {
        if ($fNetPrice <= 0.0) {
            return 0.0;
        }

        $mVat = $arProperty['postnord_vat_percent'] ?? self::DEFAULT_VAT_PERCENT;
        $fVatPercent = is_numeric($mVat) ? max(0.0, (float) $mVat) : self::DEFAULT_VAT_PERCENT;

        $mMarkup = $arProperty['postnord_price_markup'] ?? 0.0;
        $fMarkup = is_numeric($mMarkup) ? (float) $mMarkup : 0.0;

        $sRounding = trim(self::mixedToString($arProperty['postnord_price_rounding'] ?? self::ROUND_CEIL));

        $fGross = ($fNetPrice * (1.0 + ($fVatPercent / 100.0))) + $fMarkup;
        $fRounded = self::applyRounding(max(0.0, $fGross), $sRounding);

        return round(max(0.0, $fRounded), 2);
    }

    /**
     * Convert a source-currency price (e.g. NOK from PostNord Norway) into the
     * shop's default Shopaholic currency using the exact inverse of Lovata's
     * CurrencyHelper::convert() (`default.rate * price / target.rate`):
     * `default_price = (source_amount * source.rate) / default.rate`.
     */
    public static function convertToDefaultCurrency(float $fAmount, string $sSourceCurrency = 'NOK'): float
    {
        $sNormalizedSource = strtoupper(trim($sSourceCurrency));
        if ($fAmount <= 0.0 || $sNormalizedSource === '') {
            return max(0.0, round($fAmount, 2));
        }

        $fMultiplier = self::resolveSourceToDefaultMultiplier($sNormalizedSource);

        return round($fAmount * $fMultiplier, 2);
    }

    /**
     * Format a default-currency price in the shopper's active display currency.
     */
    public static function formatDisplayPrice(float $fPriceDefaultCurrency): string
    {
        try {
            /** @var CurrencyHelper $obHelper */
            $obHelper = CurrencyHelper::instance();
            $sActiveCode = self::mixedToString($obHelper->getActiveCurrencyCode());
            $fConverted = (float) $obHelper->convert($fPriceDefaultCurrency, $sActiveCode !== '' ? $sActiveCode : null);
            $sSymbol = trim(self::mixedToString($obHelper->getActiveCurrencySymbol()));
            $sFormatted = self::formatCurrencyAmount($fConverted, $sActiveCode);

            return $sSymbol !== '' ? $sSymbol . ' ' . $sFormatted : $sFormatted;
        } catch (\Throwable) {
            return number_format($fPriceDefaultCurrency, 2, '.', '');
        }
    }

    /**
     * Resolve the multiplier (`source.rate / default.rate`) to convert a source
     * currency amount into the default shop currency (exact inverse of Lovata
     * CurrencyHelper::convert()). Returns 1.0 when currencies match or are unset.
     */
    private static function resolveSourceToDefaultMultiplier(string $sNormalizedSource): float
    {
        try {
            $obDefaultCurrency = Currency::where('is_default', true)->first();
            $sDefaultCode = $obDefaultCurrency instanceof Currency
                ? strtoupper(trim(self::mixedToString($obDefaultCurrency->code)))
                : '';

            if (!$obDefaultCurrency instanceof Currency || $sDefaultCode === '' || $sDefaultCode === $sNormalizedSource) {
                return 1.0;
            }

            $obSourceCurrency = Currency::where('code', $sNormalizedSource)->first();
            $fDefaultRate = self::extractPositiveCurrencyRate($obDefaultCurrency);
            $fSourceRate = $obSourceCurrency instanceof Currency ? self::extractPositiveCurrencyRate($obSourceCurrency) : 0.0;

            return $fDefaultRate > 0.0 && $fSourceRate > 0.0 ? $fSourceRate / $fDefaultRate : 1.0;
        } catch (\Throwable) {
            return 1.0;
        }
    }

    /**
     * Extract a positive float exchange rate from a Currency model (handles MySQL DECIMAL strings).
     */
    private static function extractPositiveCurrencyRate(Currency $obCurrency): float
    {
        /** @var mixed $mRate */
        $mRate = $obCurrency->getAttribute('rate');

        return is_numeric($mRate) && (float) $mRate > 0.0 ? (float) $mRate : 0.0;
    }

    /**
     * Apply the configured rounding strategy to a gross price.
     */
    private static function applyRounding(float $fValue, string $sRounding): float
    {
        return match ($sRounding) {
            self::ROUND_CEIL    => ceil($fValue),
            self::ROUND_CEIL_5  => ceil($fValue / 5.0) * 5.0,
            self::ROUND_CEIL_10 => ceil($fValue / 10.0) * 10.0,
            default             => $fValue,
        };
    }

    /**
     * Format currency amounts matching store convention (whole-number ",-" for NOK/SEK/DKK).
     */
    private static function formatCurrencyAmount(float $fAmount, string $sCurrencyCode): string
    {
        $sCode = strtoupper(trim($sCurrencyCode));
        if (in_array($sCode, ['NOK', 'SEK', 'DKK'], true)) {
            return (string) (int) round($fAmount) . ',-';
        }

        return PriceHelper::format($fAmount);
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
