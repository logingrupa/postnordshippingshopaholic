<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Lovata\OrdersShopaholic\Classes\Item\CartPositionItem;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Models\Currency;
use Lovata\Toolbox\Classes\Item\MainItem;

/**
 * Class PostNordCartMeasurer
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Reads Shopaholic cart positions to compute total item weight (grams),
 * sorted Offer dimensions in Shopaholic's configured dimensions_measure (mm by default),
 * combined item volume in native cubic units, and default-currency-to-NOK subtotal.
 */
class PostNordCartMeasurer
{
    /**
     * Measure total item weight (grams), largest single-item sorted dimensions
     * (in Shopaholic's native dimensions_measure), combined item volume, and
     * items subtotal in default shop currency (read directly from Offer to avoid
     * CartProcessor::updateCartData recursion and MainItem::__isset pitfalls).
     *
     * @param iterable<mixed> $obPositionList
     * @return array{weight_grams: int, max_a: float, max_b: float, max_c: float, volume: float, subtotal_default: float}
     */
    public static function measureCartItems(iterable $obPositionList, int $iDefaultItemWeight): array
    {
        $fTotalGrams = 0.0;
        $fMaxA = 0.0;
        $fMaxB = 0.0;
        $fMaxC = 0.0;
        $fVolume = 0.0;
        $fSubtotalDefault = 0.0;
        $sDefaultCurrencyCode = self::resolveDefaultCurrencyCode();

        foreach ($obPositionList as $obPosition) {
            $iQuantity = (int) self::extractNumeric($obPosition, 'quantity');
            if ($iQuantity <= 0) {
                continue;
            }

            $fLineWeight = PostNordMeasureHelper::toGrams(self::extractNumeric($obPosition, 'weight'));
            $fTotalGrams += $fLineWeight > 0.0 ? $fLineWeight : (float) (max(1, $iDefaultItemWeight) * $iQuantity);

            $mOffer = self::resolveDimensionSource($obPosition);
            if ($mOffer !== null && !$mOffer instanceof CartPositionItem) {
                $fSubtotalDefault += self::extractDefaultCurrencyUnitPrice($mOffer, $sDefaultCurrencyCode) * $iQuantity;
            }

            $arItemDims = self::extractSortedOfferDimensions($obPosition);
            if ($arItemDims !== null) {
                $fMaxA = max($fMaxA, $arItemDims[0]);
                $fMaxB = max($fMaxB, $arItemDims[1]);
                $fMaxC = max($fMaxC, $arItemDims[2]);
                $fVolume += $arItemDims[0] * $arItemDims[1] * $arItemDims[2] * $iQuantity;
            }
        }

        return [
            'weight_grams'     => (int) ceil($fTotalGrams),
            'max_a'            => $fMaxA,
            'max_b'            => $fMaxB,
            'max_c'            => $fMaxC,
            'volume'           => $fVolume,
            'subtotal_default' => round($fSubtotalDefault, 2),
        ];
    }

    /**
     * Convert a default-shop-currency cart subtotal into NOK matching Lovata
     * CurrencyHelper::convert() (`(default.rate * amount) / nok.rate`).
     */
    public static function convertDefaultCurrencyToNok(float $fAmountDefault): float
    {
        try {
            $obDefault = Currency::where('is_default', true)->first();
            if (!$obDefault instanceof Currency || strtoupper(trim((string) $obDefault->code)) === 'NOK') {
                return round($fAmountDefault, 2);
            }

            $obNok = Currency::where('code', 'NOK')->first();
            $fDefaultRate = self::extractNumeric($obDefault->getAttributes(), 'rate');
            $fNokRate = $obNok instanceof Currency ? self::extractNumeric($obNok->getAttributes(), 'rate') : 0.0;
            if ($fDefaultRate > 0.0 && $fNokRate > 0.0) {
                return round(($fAmountDefault * $fDefaultRate) / $fNokRate, 2);
            }
        } catch (\Throwable) {
            return round($fAmountDefault, 2);
        }

        return round($fAmountDefault, 2);
    }

    /**
     * Extract an Offer's unit price in the shop's default currency (never double-converted
     * when the shopper is viewing checkout in a secondary active currency like NOK).
     */
    private static function extractDefaultCurrencyUnitPrice(mixed $mOffer, string $sDefaultCurrencyCode): float
    {
        if ($mOffer instanceof OfferItem) {
            try {
                if ($sDefaultCurrencyCode !== '') {
                    $mOffer->setActiveCurrency($sDefaultCurrencyCode);
                }
                /** @var mixed $mPrice */
                $mPrice = $mOffer->price_value;

                return is_numeric($mPrice) ? max(0.0, (float) $mPrice) : 0.0;
            } finally {
                $mOffer->setActiveCurrency('');
            }
        }

        return self::extractNumeric($mOffer, 'price_value');
    }

    /**
     * Extract offer length/width/height in Shopaholic's native dimensions_measure, sorted descending.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function extractSortedOfferDimensions(mixed $obPosition): ?array
    {
        $mSource = self::resolveDimensionSource($obPosition);
        if ($mSource === null) {
            return null;
        }

        $fLength = self::extractNumeric($mSource, 'length');
        $fWidth = self::extractNumeric($mSource, 'width');
        $fHeight = self::extractNumeric($mSource, 'height');
        if ($fLength <= 0.0 || $fWidth <= 0.0 || $fHeight <= 0.0) {
            return null;
        }

        $arDims = [$fLength, $fWidth, $fHeight];
        rsort($arDims, SORT_NUMERIC);

        return [$arDims[0], $arDims[1], $arDims[2]];
    }

    /**
     * Resolve the object/array carrying length/width/height (CartPositionItem->offer or direct stub).
     */
    private static function resolveDimensionSource(mixed $obPosition): mixed
    {
        if (!is_array($obPosition) && !is_object($obPosition)) {
            return null;
        }

        $mOffer = self::readField($obPosition, 'offer');

        return is_object($mOffer) || is_array($mOffer) ? $mOffer : $obPosition;
    }

    /**
     * Resolve the shop's default Currency code (e.g. 'EUR' or 'NOK'), or empty string.
     */
    private static function resolveDefaultCurrencyCode(): string
    {
        try {
            $obDefault = Currency::where('is_default', true)->first();

            return $obDefault instanceof Currency ? strtoupper(trim((string) $obDefault->code)) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Extract non-negative numeric field from array, MainItem, or plain object.
     */
    private static function extractNumeric(mixed $mSource, string $sField): float
    {
        $mValue = self::readField($mSource, $sField);

        return is_numeric($mValue) ? max(0.0, (float) $mValue) : 0.0;
    }

    /**
     * Read a field from an array, Lovata MainItem (direct property access avoids
     * MainItem::__isset missing computed attributes), or plain object.
     */
    private static function readField(mixed $mSource, string $sField): mixed
    {
        if (is_array($mSource)) {
            return $mSource[$sField] ?? null;
        }
        if ($mSource instanceof MainItem) {
            return $mSource->{$sField};
        }
        if (is_object($mSource) && property_exists($mSource, $sField)) {
            return $mSource->{$sField};
        }

        return null;
    }
}
