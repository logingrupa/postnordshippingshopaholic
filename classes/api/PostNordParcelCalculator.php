<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;

/**
 * Class PostNordParcelCalculator
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Computes total parcel weight (items + packaging tare) in grams and outer
 * dimensions in centimeters for PostNord Norway's B2C Products API consignment.colli.
 * All backend box/envelope inputs and Shopaholic Offer dimensions are compared in
 * Shopaholic's configured native measure (mm & gr by default) and converted to
 * PostNord's required cm & grams on output.
 */
class PostNordParcelCalculator
{
    public const DEFAULT_ENVELOPE_MAX_WEIGHT_GRAMS = 1000;
    public const DEFAULT_ENVELOPE_LENGTH_CM = 18;
    public const DEFAULT_ENVELOPE_WIDTH_CM = 12;
    public const DEFAULT_ENVELOPE_HEIGHT_CM = 4;

    private const PACKING_FILL_RATIO = 0.85;

    /**
     * Calculate parcel weight (grams), dimensions (cm), and optional NOK cart
     * total from an iterable of cart positions and ShippingType properties.
     *
     * @param iterable<mixed> $obPositionList
     * @param array<mixed, mixed> $arProperty
     * @param float|null $fCartTotalDefaultCurrency Optional cart items subtotal in shop default currency
     * @return array{weight: int, length: int|null, width: int|null, height: int|null, cart_total_nok: float|null, parcel_letter_max_price: float|null, packaging: string}
     */
    public static function calculateParcelForCart(
        iterable $obPositionList,
        array $arProperty,
        ?float $fCartTotalDefaultCurrency = null,
    ): array {
        $iDefaultItemWeight = PostNordPriceCalculator::resolveDefaultWeightGrams($arProperty);
        $arMetrics = PostNordCartMeasurer::measureCartItems($obPositionList, $iDefaultItemWeight);

        $fRawTare = self::positiveFloat($arProperty['postnord_packaging_weight'] ?? 0) ?? 0.0;
        $iTareWeight = (int) ceil(PostNordMeasureHelper::toGrams($fRawTare));
        $iBaseWeight = $arMetrics['weight_grams'] > 0 ? $arMetrics['weight_grams'] : $iDefaultItemWeight;
        $iTotalWeight = max(PostNordPriceCalculator::MIN_WEIGHT_GRAMS, $iBaseWeight + $iTareWeight);

        $arDims = self::selectPackagingDimensions($arMetrics, $arProperty);
        $fMaxLetterPrice = self::positiveFloat($arProperty['postnord_parcel_letter_max_price'] ?? 0.0);
        $fEffectiveSubtotal = $fCartTotalDefaultCurrency ?? ($arMetrics['subtotal_default'] > 0.0 ? $arMetrics['subtotal_default'] : null);
        $fCartTotalNok = $fMaxLetterPrice !== null && $fEffectiveSubtotal !== null
            ? PostNordCartMeasurer::convertDefaultCurrencyToNok($fEffectiveSubtotal)
            : null;

        return [
            'weight'                  => $iTotalWeight,
            'length'                  => $arDims['length'],
            'width'                   => $arDims['width'],
            'height'                  => $arDims['height'],
            'cart_total_nok'          => $fCartTotalNok,
            'parcel_letter_max_price' => $fMaxLetterPrice,
            'packaging'               => $arDims['packaging'],
        ];
    }

    /**
     * Calculate the parcel spec for the current active Shopaholic cart.
     *
     * @param array<mixed, mixed> $arProperty
     * @return array{weight: int, length: int|null, width: int|null, height: int|null, cart_total_nok: float|null, parcel_letter_max_price: float|null, packaging: string}
     */
    public static function calculateCurrentParcel(array $arProperty): array
    {
        try {
            /** @var CartProcessor $obCartProcessor */
            $obCartProcessor = CartProcessor::instance();
            /** @var iterable<mixed> $obPositionList */
            $obPositionList = $obCartProcessor->get();

            return self::calculateParcelForCart($obPositionList, $arProperty);
        } catch (\Throwable) {
            return self::calculateParcelForCart([], $arProperty, null);
        }
    }

    /**
     * Build a deterministic cache/comparison signature for a parcel spec.
     *
     * @param array{weight: int, length: int|null, width: int|null, height: int|null, cart_total_nok?: float|null, parcel_letter_max_price?: float|null} $arParcel
     */
    public static function buildParcelSignature(array $arParcel): string
    {
        $sDims = ($arParcel['length'] ?? 0) . 'x' . ($arParcel['width'] ?? 0) . 'x' . ($arParcel['height'] ?? 0);
        $fMax = $arParcel['parcel_letter_max_price'] ?? null;
        $fCart = $arParcel['cart_total_nok'] ?? null;
        $sCapState = ($fMax !== null && $fMax > 0.0 && $fCart !== null)
            ? ($fCart > $fMax ? 'over' : 'under')
            : 'nocap';

        return $arParcel['weight'] . '|' . $sDims . '|' . $sCapState;
    }

    /**
     * Choose bubble envelope vs default box in native catalog units, returning PostNord cm integers.
     *
     * @param array{weight_grams: int, max_a: float, max_b: float, max_c: float, volume: float, subtotal_default: float} $arMetrics
     * @param array<mixed, mixed> $arProperty
     * @return array{length: int|null, width: int|null, height: int|null, packaging: string}
     */
    private static function selectPackagingDimensions(array $arMetrics, array $arProperty): array
    {
        $arEnvelope = self::resolveFittingEnvelope($arMetrics, $arProperty);
        if ($arEnvelope !== null) {
            return self::formatPostNordDimensions($arEnvelope[0], $arEnvelope[1], $arEnvelope[2], 'envelope');
        }

        $arBox = self::resolveBoxDimensions($arProperty);
        if ($arBox !== null) {
            return self::fitBoxAroundItems($arBox[0], $arBox[1], $arBox[2], $arMetrics);
        }

        if ($arMetrics['max_a'] > 0.0 && $arMetrics['max_b'] > 0.0 && $arMetrics['max_c'] > 0.0) {
            return self::fitBoxAroundItems($arMetrics['max_a'], $arMetrics['max_b'], $arMetrics['max_c'], $arMetrics);
        }

        return ['length' => null, 'width' => null, 'height' => null, 'packaging' => 'none'];
    }

    /**
     * Return sorted envelope native dimensions if enabled and the cart items fit inside it.
     *
     * @param array{weight_grams: int, max_a: float, max_b: float, max_c: float, volume: float, subtotal_default: float} $arMetrics
     * @param array<mixed, mixed> $arProperty
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function resolveFittingEnvelope(array $arMetrics, array $arProperty): ?array
    {
        if (!filter_var($arProperty['postnord_envelope_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $fRawMaxWeight = self::positiveFloat(
            $arProperty['postnord_envelope_max_weight'] ?? PostNordMeasureHelper::fromGrams(self::DEFAULT_ENVELOPE_MAX_WEIGHT_GRAMS)
        );
        $iMaxWeightGrams = $fRawMaxWeight !== null ? (int) ceil(PostNordMeasureHelper::toGrams($fRawMaxWeight)) : 0;
        if ($iMaxWeightGrams > 0 && $arMetrics['weight_grams'] > $iMaxWeightGrams) {
            return null;
        }

        $arEnv = self::resolvePositiveTriple(
            self::positiveFloat($arProperty['postnord_envelope_length'] ?? PostNordMeasureHelper::fromCentimeters(self::DEFAULT_ENVELOPE_LENGTH_CM)),
            self::positiveFloat($arProperty['postnord_envelope_width'] ?? PostNordMeasureHelper::fromCentimeters(self::DEFAULT_ENVELOPE_WIDTH_CM)),
            self::positiveFloat($arProperty['postnord_envelope_height'] ?? PostNordMeasureHelper::fromCentimeters(self::DEFAULT_ENVELOPE_HEIGHT_CM)),
        );
        if ($arEnv === null || !self::itemsFitInside($arMetrics, $arEnv)) {
            return null;
        }

        return $arEnv;
    }

    /**
     * Check whether single-item axes and combined cart volume fit within sorted native bounds.
     *
     * @param array{weight_grams: int, max_a: float, max_b: float, max_c: float, volume: float, subtotal_default: float} $arMetrics
     * @param array{0: float, 1: float, 2: float} $arBounds
     */
    private static function itemsFitInside(array $arMetrics, array $arBounds): bool
    {
        if ($arMetrics['max_a'] > $arBounds[0] || $arMetrics['max_b'] > $arBounds[1] || $arMetrics['max_c'] > $arBounds[2]) {
            return false;
        }

        $fUsableVol = ($arBounds[0] * $arBounds[1] * $arBounds[2]) * self::PACKING_FILL_RATIO;

        return $arMetrics['volume'] <= $fUsableVol;
    }

    /**
     * Resolve configured default box dimensions in native catalog units, or null if incomplete.
     *
     * @param array<mixed, mixed> $arProperty
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function resolveBoxDimensions(array $arProperty): ?array
    {
        return self::resolvePositiveTriple(
            self::positiveFloat($arProperty['postnord_box_length'] ?? 0),
            self::positiveFloat($arProperty['postnord_box_width'] ?? 0),
            self::positiveFloat($arProperty['postnord_box_height'] ?? 0),
        );
    }

    /**
     * Expand a base shipping box (in native units) when any item or combined volume exceeds it,
     * then convert to integer centimeters for PostNord Norway.
     *
     * @param array{weight_grams: int, max_a: float, max_b: float, max_c: float, volume: float, subtotal_default: float} $arMetrics
     * @return array{length: int, width: int, height: int, packaging: string}
     */
    private static function fitBoxAroundItems(float $fBoxL, float $fBoxW, float $fBoxH, array $arMetrics): array
    {
        $arSorted = self::resolveSortedDimensions($fBoxL, $fBoxW, $fBoxH);
        $fLength = max($arSorted[0], $arMetrics['max_a']);
        $fWidth = max($arSorted[1], $arMetrics['max_b']);
        $fHeight = max($arSorted[2], $arMetrics['max_c']);

        $fBaseArea = max(1.0, $fLength * $fWidth) * self::PACKING_FILL_RATIO;
        $fRequiredHeight = $arMetrics['volume'] > 0.0 ? ($arMetrics['volume'] / $fBaseArea) : $fHeight;

        return self::formatPostNordDimensions($fLength, $fWidth, max($fHeight, $fRequiredHeight), 'box');
    }

    /**
     * Convert native catalog dimensions to integer centimeters (rounded up) for PostNord colli.
     *
     * @return array{length: int, width: int, height: int, packaging: string}
     */
    private static function formatPostNordDimensions(float $fLength, float $fWidth, float $fHeight, string $sPackaging): array
    {
        return [
            'length'    => max(1, (int) ceil(PostNordMeasureHelper::toCentimeters($fLength))),
            'width'     => max(1, (int) ceil(PostNordMeasureHelper::toCentimeters($fWidth))),
            'height'    => max(1, (int) ceil(PostNordMeasureHelper::toCentimeters($fHeight))),
            'packaging' => $sPackaging,
        ];
    }

    /**
     * Return three sorted positive dimensions, or null when any axis is missing/non-positive.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function resolvePositiveTriple(?float $fFirst, ?float $fSecond, ?float $fThird): ?array
    {
        if ($fFirst === null || $fSecond === null || $fThird === null) {
            return null;
        }

        return self::resolveSortedDimensions($fFirst, $fSecond, $fThird);
    }

    /**
     * Sort three positive dimensions descending.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function resolveSortedDimensions(float $fFirst, float $fSecond, float $fThird): array
    {
        $arDims = [$fFirst, $fSecond, $fThird];
        rsort($arDims, SORT_NUMERIC);

        return [$arDims[0], $arDims[1], $arDims[2]];
    }

    /**
     * Parse a strictly positive float from mixed input, or null.
     */
    private static function positiveFloat(mixed $mValue): ?float
    {
        return is_numeric($mValue) && (float) $mValue > 0.0 ? (float) $mValue : null;
    }
}
