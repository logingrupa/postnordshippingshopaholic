<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Illuminate\Support\Facades\Lang;
use Lovata\Shopaholic\Models\Measure;
use Lovata\Shopaholic\Models\Settings;

/**
 * Class PostNordMeasureHelper
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Mirrors Lovata.Shopaholic's ExtendOfferFieldsHandler unit conventions by
 * reading `dimensions_measure` and `weight_measure` from Shopaholic Settings
 * for backend field labels and converting catalog units (mm/cm/m, gr/kg) to
 * PostNord Norway's required consignment.colli units (cm, grams).
 */
class PostNordMeasureHelper
{
    public const DEFAULT_DIMENSIONS_UNIT = 'mm';
    public const DEFAULT_WEIGHT_UNIT = 'gr';

    /** @var array<string, array{code: string, name: string}> */
    private static array $arMeasureCache = [];

    /**
     * Clear memoized measure lookups (used in tests when Settings/Measure change).
     */
    public static function resetCache(): void
    {
        self::$arMeasureCache = [];
    }

    /**
     * Format a dimension field label appending the active Shopaholic dimensions measure (e.g. "(mm)").
     */
    public static function formatDimensionLabel(string $sLangPath): string
    {
        return self::appendMeasureLabel($sLangPath, self::resolveMeasure('dimensions_measure', self::DEFAULT_DIMENSIONS_UNIT)['name']);
    }

    /**
     * Format a weight field label appending the active Shopaholic weight measure (e.g. "(gr)").
     */
    public static function formatWeightLabel(string $sLangPath): string
    {
        return self::appendMeasureLabel($sLangPath, self::resolveMeasure('weight_measure', self::DEFAULT_WEIGHT_UNIT)['name']);
    }

    /**
     * Convert a dimension value in Shopaholic's active dimensions_measure into centimeters for PostNord.
     */
    public static function toCentimeters(float $fValue): float
    {
        return match (self::getDimensionsUnitCode()) {
            'cm'    => $fValue,
            'm'     => $fValue * 100.0,
            default => $fValue / 10.0,
        };
    }

    /**
     * Convert a centimeter default (e.g. 18 cm) into Shopaholic's active dimensions_measure (e.g. 180 mm).
     */
    public static function fromCentimeters(int $iCentimeters): int
    {
        return match (self::getDimensionsUnitCode()) {
            'cm'    => $iCentimeters,
            'm'     => max(1, (int) round($iCentimeters / 100.0)),
            default => $iCentimeters * 10,
        };
    }

    /**
     * Convert a weight value in Shopaholic's active weight_measure into grams for PostNord.
     */
    public static function toGrams(float $fValue): float
    {
        return match (self::getWeightUnitCode()) {
            'kg'    => $fValue * 1000.0,
            default => $fValue,
        };
    }

    /**
     * Convert a gram default (e.g. 1000 g) into Shopaholic's active weight_measure.
     */
    public static function fromGrams(int $iGrams): float
    {
        return match (self::getWeightUnitCode()) {
            'kg'    => round($iGrams / 1000.0, 3),
            default => (float) $iGrams,
        };
    }

    /**
     * Resolve the normalized lowercased dimensions unit code ('mm', 'cm', 'm').
     */
    public static function getDimensionsUnitCode(): string
    {
        return self::resolveMeasure('dimensions_measure', self::DEFAULT_DIMENSIONS_UNIT)['code'];
    }

    /**
     * Resolve the normalized lowercased weight unit code ('gr', 'g', 'kg').
     */
    public static function getWeightUnitCode(): string
    {
        return self::resolveMeasure('weight_measure', self::DEFAULT_WEIGHT_UNIT)['code'];
    }

    /**
     * Resolve and memoize both code and display name for a configured Shopaholic measure setting.
     *
     * @return array{code: string, name: string}
     */
    private static function resolveMeasure(string $sSettingKey, string $sFallback): array
    {
        if (isset(self::$arMeasureCache[$sSettingKey])) {
            return self::$arMeasureCache[$sSettingKey];
        }

        $obMeasure = self::findConfiguredMeasure($sSettingKey);
        if (!$obMeasure instanceof Measure) {
            return ['code' => $sFallback, 'name' => $sFallback];
        }

        $sName = trim((string) $obMeasure->name);
        $sCode = trim((string) $obMeasure->code);
        $sResolvedName = $sName !== '' ? $sName : $sFallback;
        $sResolvedCode = strtolower($sCode !== '' ? $sCode : $sResolvedName);

        self::$arMeasureCache[$sSettingKey] = [
            'code' => $sResolvedCode,
            'name' => $sResolvedName,
        ];

        return self::$arMeasureCache[$sSettingKey];
    }

    /**
     * Look up a Measure model referenced by a Shopaholic Settings key safely.
     */
    private static function findConfiguredMeasure(string $sSettingKey): ?Measure
    {
        try {
            $mMeasureId = Settings::getValue($sSettingKey);
            if (!is_numeric($mMeasureId) || (int) $mMeasureId <= 0) {
                return null;
            }

            $obMeasure = Measure::find((int) $mMeasureId);

            return $obMeasure instanceof Measure ? $obMeasure : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Append measure suffix in parentheses matching Lovata.Shopaholic's ExtendOfferFieldsHandler.
     */
    private static function appendMeasureLabel(string $sLangPath, string $sUnitName): string
    {
        $sLabel = $sLangPath;
        try {
            $mTranslated = Lang::get($sLangPath);
            if (is_string($mTranslated) && $mTranslated !== '') {
                $sLabel = $mTranslated;
            }
        } catch (\Throwable) {
            $sLabel = $sLangPath;
        }

        return $sUnitName !== '' ? $sLabel . ' (' . $sUnitName . ')' : $sLabel;
    }
}
