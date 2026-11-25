<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

/**
 * Class PostNordNorwayPayloadBuilder
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Builds the JSON request body for PostNord Norway's POST /rest/api/products
 * endpoint, including consignment.colli weight/dimensions (cm) and optional
 * parcelLetterMaxPrice / shoppingCartTotalPrice limits for Home Small (B).
 */
class PostNordNorwayPayloadBuilder
{
    /**
     * Build the POST /rest/api/products request body.
     *
     * @param list<string> $arProducts
     * @param array<string, mixed>|null $arParcel
     * @return array{settings: array<string, mixed>, consignment: array<string, mixed>}
     */
    public static function buildProductsPayload(
        string $sCustomerId,
        string $sSenderCode,
        string $sPostalCode,
        int $iMaxResults,
        ?int $iWeightGrams = null,
        array $arProducts = [],
        ?array $arParcel = null,
    ): array {
        /** @var array<string, mixed> $arSettings */
        $arSettings = [
            'customerId' => $sCustomerId,
            'maxSpCount' => $iMaxResults,
        ];
        if ($arProducts !== []) {
            $arSettings['products'] = $arProducts;
        }

        $fLetterCap = self::extractPositiveFloat($arParcel, 'parcel_letter_max_price');
        if ($fLetterCap !== null) {
            $arSettings['parcelLetterMaxPrice'] = [
                'value'    => $fLetterCap,
                'currency' => 'NOK',
            ];
        }

        /** @var array<string, mixed> $arConsignment */
        $arConsignment = [
            'consignor' => ['postalCode' => $sSenderCode],
            'consignee' => ['postalCode' => $sPostalCode],
        ];

        $arColli = self::buildColliEntry($iWeightGrams, $arParcel);
        if ($arColli !== null) {
            $arConsignment['colli'] = [$arColli];
        }

        $fCartNok = self::extractPositiveFloat($arParcel, 'cart_total_nok');
        if ($fCartNok !== null) {
            $arConsignment['shoppingCartTotalPrice'] = [
                'value'    => $fCartNok,
                'currency' => 'NOK',
            ];
        }

        return [
            'settings'    => $arSettings,
            'consignment' => $arConsignment,
        ];
    }

    /**
     * Build a single colli payload entry carrying weight (g) and optional cm dimensions.
     *
     * @param array<string, mixed>|null $arParcel
     * @return array<string, int>|null
     */
    private static function buildColliEntry(?int $iWeightGrams, ?array $arParcel): ?array
    {
        $iEffectiveWeight = self::resolveEffectiveWeight($iWeightGrams, $arParcel);
        if ($iEffectiveWeight <= 0) {
            return null;
        }

        $arColli = ['weight' => $iEffectiveWeight];
        if (!is_array($arParcel)) {
            return $arColli;
        }

        foreach (['length', 'width', 'height'] as $sAxis) {
            $iAxis = self::extractPositiveInt($arParcel, $sAxis);
            if ($iAxis > 0) {
                $arColli[$sAxis] = $iAxis;
            }
        }

        return $arColli;
    }

    /**
     * Resolve effective colli weight in grams from parcel data or explicit override.
     *
     * @param array<string, mixed>|null $arParcel
     */
    private static function resolveEffectiveWeight(?int $iWeightGrams, ?array $arParcel): int
    {
        $iParcelWeight = self::extractPositiveInt($arParcel, 'weight');
        if ($iParcelWeight > 0) {
            return $iParcelWeight;
        }

        return $iWeightGrams !== null && $iWeightGrams > 0 ? $iWeightGrams : 0;
    }

    /**
     * Extract a positive integer field from an optional parcel array.
     *
     * @param array<string, mixed>|null $arParcel
     */
    private static function extractPositiveInt(?array $arParcel, string $sKey): int
    {
        if (!is_array($arParcel)) {
            return 0;
        }

        $mValue = $arParcel[$sKey] ?? null;

        return is_numeric($mValue) && (int) $mValue > 0 ? (int) $mValue : 0;
    }

    /**
     * Extract a positive float field from an optional parcel array.
     *
     * @param array<string, mixed>|null $arParcel
     */
    private static function extractPositiveFloat(?array $arParcel, string $sKey): ?float
    {
        if (!is_array($arParcel)) {
            return null;
        }

        $mValue = $arParcel[$sKey] ?? null;

        return is_numeric($mValue) && (float) $mValue > 0.0 ? (float) $mValue : null;
    }
}
