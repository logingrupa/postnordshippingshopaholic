<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;

/**
 * Class PostNordCatalogPresenter
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Hydrates ordered ServicePoint models with per-search distances and customer
 * prices, and formats selectable PostNord delivery option cards + quote maps
 * carrying authoritative carrier metadata for order integrity.
 */
class PostNordCatalogPresenter
{
    /**
     * Build formatted delivery option cards and server-side product quotes.
     *
     * @param list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}> $arProducts
     * @param array<mixed, mixed> $arProperty
     * @return array{options: list<array<string, mixed>>, quotes: array<string, array{price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}>}
     */
    public static function buildDeliveryOptionList(
        array $arProducts,
        bool $bDynamicPricing,
        array $arProperty,
    ): array {
        if (!$bDynamicPricing || $arProducts === []) {
            return ['options' => [], 'quotes' => []];
        }

        /** @var list<array<string, mixed>> $arOptions */
        $arOptions = [];
        /** @var array<string, array{price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}> $arQuotes */
        $arQuotes = [];

        foreach ($arProducts as $arProduct) {
            $sPriceFormatted = '';
            $fDefaultPrice = null;

            if ($arProduct['price_net'] !== null && $arProduct['price_net'] > 0.0) {
                $fCustomer = PostNordPriceCalculator::calculateCustomerPrice($arProduct['price_net'], $arProperty);
                $fDefaultPrice = PostNordPriceCalculator::convertToDefaultCurrency($fCustomer, $arProduct['price_currency']);
                $sPriceFormatted = PostNordPriceCalculator::formatDisplayPrice($fDefaultPrice);

                $arQuotes['product:' . $arProduct['product_code']] = [
                    'price_default'         => $fDefaultPrice,
                    'price_net'             => $arProduct['price_net'],
                    'currency'              => $arProduct['price_currency'],
                    'product_code'          => $arProduct['product_code'],
                    'product_name'          => $arProduct['display_name'],
                    'service_point_name'    => '',
                    'service_point_address' => '',
                ];
            }

            $arOptions[] = [
                'product_code'       => $arProduct['product_code'],
                'display_name'       => $arProduct['display_name'],
                'description'        => $arProduct['description'],
                'tip'                => $arProduct['tip'],
                'working_days'       => $arProduct['working_days'],
                'needs_pickup_point' => $arProduct['needs_pickup_point'],
                'price_value'        => $fDefaultPrice,
                'price_formatted'    => $sPriceFormatted,
            ];
        }

        return ['options' => $arOptions, 'quotes' => $arQuotes];
    }

    /**
     * Load ServicePoint models in a single query, preserving distance order,
     * applying per-search distances, and computing dynamic customer prices.
     *
     * @param list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}> $arEntryList
     * @param array<mixed, mixed> $arProperty
     * @param array<string, string> $arProductNameMap Optional map of product_code => display_name
     * @return array{points: list<ServicePoint>, quotes: array<string, array{price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}>}
     */
    public static function loadOrderedServicePoints(
        array $arEntryList,
        bool $bDynamicPricing,
        array $arProperty,
        array $arProductNameMap = [],
    ): array {
        if ($arEntryList === []) {
            return ['points' => [], 'quotes' => []];
        }

        $arIdList = array_map(static fn (array $arEntry): int => $arEntry['id'], $arEntryList);
        $obPointCollection = ServicePoint::whereIn('id', $arIdList)->get()->keyBy('id');

        /** @var list<ServicePoint> $arServicePointList */
        $arServicePointList = [];
        /** @var array<string, array{price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}> $arQuoteMap */
        $arQuoteMap = [];

        foreach ($arEntryList as $arEntry) {
            $obServicePoint = $obPointCollection->get($arEntry['id']);
            if (!$obServicePoint instanceof ServicePoint) {
                continue;
            }

            $obServicePoint->distance_in_meters = $arEntry['distance_in_meters'];
            $obServicePoint->setAttribute('product_code', $arEntry['product_code']);

            $arQuote = self::applyPointDynamicPrice($obServicePoint, $arEntry, $bDynamicPricing, $arProperty, $arProductNameMap);
            if ($arQuote !== null) {
                $arQuoteMap[(string) $obServicePoint->service_point_id] = $arQuote;
            }

            $arServicePointList[] = $obServicePoint;
        }

        return [
            'points' => $arServicePointList,
            'quotes' => $arQuoteMap,
        ];
    }

    /**
     * Attach formatted dynamic customer price attributes to a ServicePoint and return its quote entry.
     *
     * @param array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string} $arEntry
     * @param array<mixed, mixed> $arProperty
     * @param array<string, string> $arProductNameMap
     * @return array{price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}|null
     */
    private static function applyPointDynamicPrice(
        ServicePoint $obServicePoint,
        array $arEntry,
        bool $bDynamicPricing,
        array $arProperty,
        array $arProductNameMap,
    ): ?array {
        if (!$bDynamicPricing || $arEntry['price_net'] === null || $arEntry['price_net'] <= 0.0) {
            return null;
        }

        $fCustomer = PostNordPriceCalculator::calculateCustomerPrice($arEntry['price_net'], $arProperty);
        $fDefault = PostNordPriceCalculator::convertToDefaultCurrency($fCustomer, $arEntry['price_currency']);
        $sFormatted = PostNordPriceCalculator::formatDisplayPrice($fDefault);

        $obServicePoint->setAttribute('customer_price', $fDefault);
        $obServicePoint->setAttribute('customer_price_formatted', $sFormatted);

        return [
            'price_default'         => $fDefault,
            'price_net'             => $arEntry['price_net'],
            'currency'              => $arEntry['price_currency'],
            'product_code'          => $arEntry['product_code'],
            'product_name'          => $arProductNameMap[$arEntry['product_code']] ?? '',
            'service_point_name'    => trim((string) $obServicePoint->name),
            'service_point_address' => self::formatServicePointAddress($obServicePoint),
        ];
    }

    /**
     * Format a ServicePoint's street, postal code, and city into a single display address.
     */
    private static function formatServicePointAddress(ServicePoint $obServicePoint): string
    {
        $sStreet = trim((string) $obServicePoint->street_name . ' ' . (string) $obServicePoint->street_number);
        $sLocality = trim((string) $obServicePoint->postal_code . ' ' . (string) $obServicePoint->city);

        return implode(', ', array_filter([$sStreet, $sLocality], static fn (string $sPart): bool => $sPart !== ''));
    }
}
