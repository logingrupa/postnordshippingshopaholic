<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\Order;

use Event;
use Illuminate\Support\Facades\Session;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordPriceCalculator;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordSessionQuoteStore;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\ShippingType;

/**
 * Class ExtendOrderModel
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\Order
 *
 * Writes PostNord service point & delivery method properties onto the Order model
 * during `shopaholic.order.after_create` (authoritative server quote values when
 * dynamic pricing is active, or session fallback when flat-rate/Nordic) and clears
 * session state only on `shopaholic.order.created` (after DB commit) so rolled-back
 * payment failures retain the active quote for retry.
 */
class ExtendOrderModel
{
    private const SESSION_KEY_POINT_ID = 'postnord_service_point_id';
    private const SESSION_KEY_POINT_NAME = 'postnord_service_point_name';
    private const SESSION_KEY_POINT_ADDRESS = 'postnord_service_point_address';
    private const SESSION_KEY_PRODUCT_CODE = 'postnord_product_code';
    private const SESSION_KEY_PRODUCT_NAME = 'postnord_product_name';

    /**
     * Register event listeners.
     *
     * @param \Illuminate\Events\Dispatcher $obDispatcher
     */
    public function subscribe($obDispatcher): void
    {
        Event::listen(
            'shopaholic.order.after_create',
            [self::class, 'handleAfterCreate']
        );
        Event::listen(
            'shopaholic.order.created',
            [self::class, 'handleOrderCreated']
        );
    }

    /**
     * Handle order after create event (inside transaction).
     * Never clears session here so payment rollbacks keep the active quote intact.
     *
     * @param Order $obOrder The created order
     */
    public static function handleAfterCreate(Order $obOrder): void
    {
        if (!self::isPostNordOrder($obOrder)) {
            return;
        }

        /** @var mixed $mPropertyList */
        $mPropertyList = $obOrder->property;
        $arPropertyList = is_array($mPropertyList) ? $mPropertyList : [];

        $arQuote = self::resolveAuthoritativeDynamicQuote($obOrder);
        if ($arQuote !== null) {
            $arUpdated = self::applyAuthoritativeQuoteProperties($arPropertyList, $arQuote);
        } else {
            $arSession = self::readSessionData();
            if ($arSession['point_id'] === '' && $arSession['product_code'] === '') {
                return;
            }
            $arUpdated = self::mergeFallbackProperties($arPropertyList, $arSession);
        }

        if ($arUpdated !== $arPropertyList) {
            $obOrder->property = $arUpdated;
            $obOrder->save();
        }
    }

    /**
     * Clear all PostNord session keys and quotes after the order transaction commits.
     */
    public static function handleOrderCreated(): void
    {
        Session::forget([
            self::SESSION_KEY_POINT_ID,
            self::SESSION_KEY_POINT_NAME,
            self::SESSION_KEY_POINT_ADDRESS,
            self::SESSION_KEY_PRODUCT_CODE,
            self::SESSION_KEY_PRODUCT_NAME,
        ]);
        PostNordSessionQuoteStore::clearSessionQuotes();
    }

    /**
     * Return the active server quote for a dynamic-pricing PostNord order, or null.
     *
     * @return array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string}|null
     */
    private static function resolveAuthoritativeDynamicQuote(Order $obOrder): ?array
    {
        /** @var mixed $mShippingType */
        $mShippingType = $obOrder->shipping_type;
        if (!$mShippingType instanceof ShippingType) {
            return null;
        }

        /** @var mixed $mTypeProperty */
        $mTypeProperty = $mShippingType->property;
        $arTypeProperty = is_array($mTypeProperty) ? $mTypeProperty : [];
        if (!PostNordPriceCalculator::isDynamicPricingEnabled($arTypeProperty)) {
            return null;
        }

        $iShippingTypeId = (int) $mShippingType->id;

        return PostNordSessionQuoteStore::getActiveSelectedQuote($iShippingTypeId);
    }

    /**
     * Write authoritative carrier metadata from the billed server quote onto the order,
     * ensuring 1C export and shipping label match the exact service that was priced.
     *
     * @param array<mixed, mixed> $arPropertyList
     * @param array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name: string, service_point_name: string, service_point_address: string} $arQuote
     * @return array<mixed, mixed>
     */
    private static function applyAuthoritativeQuoteProperties(array $arPropertyList, array $arQuote): array
    {
        $bIsProductQuote = str_starts_with($arQuote['service_point_id'], 'product:');

        $arPropertyList[self::SESSION_KEY_PRODUCT_CODE] = $arQuote['product_code'];
        if ($arQuote['product_name'] !== '') {
            $arPropertyList[self::SESSION_KEY_PRODUCT_NAME] = $arQuote['product_name'];
        }

        if ($bIsProductQuote) {
            $arPropertyList[self::SESSION_KEY_POINT_ID] = '';
            $arPropertyList[self::SESSION_KEY_POINT_NAME] = '';
            $arPropertyList[self::SESSION_KEY_POINT_ADDRESS] = '';
        } else {
            $arPropertyList[self::SESSION_KEY_POINT_ID] = $arQuote['service_point_id'];
            if ($arQuote['service_point_name'] !== '') {
                $arPropertyList[self::SESSION_KEY_POINT_NAME] = $arQuote['service_point_name'];
            }
            if ($arQuote['service_point_address'] !== '') {
                $arPropertyList[self::SESSION_KEY_POINT_ADDRESS] = $arQuote['service_point_address'];
            }
        }

        return $arPropertyList;
    }

    /**
     * Read PostNord session keys without clearing them.
     *
     * @return array{point_id: string, point_name: string, point_address: string, product_code: string, product_name: string}
     */
    private static function readSessionData(): array
    {
        return [
            'point_id'      => self::readSessionString(self::SESSION_KEY_POINT_ID),
            'point_name'    => self::readSessionString(self::SESSION_KEY_POINT_NAME),
            'point_address' => self::readSessionString(self::SESSION_KEY_POINT_ADDRESS),
            'product_code'  => self::readSessionString(self::SESSION_KEY_PRODUCT_CODE),
            'product_name'  => self::readSessionString(self::SESSION_KEY_PRODUCT_NAME),
        ];
    }

    /**
     * Merge session fallback values into order properties without overwriting form-submitted values.
     *
     * @param array<mixed, mixed> $arPropertyList
     * @param array{point_id: string, point_name: string, point_address: string, product_code: string, product_name: string} $arSession
     * @return array<mixed, mixed>
     */
    private static function mergeFallbackProperties(array $arPropertyList, array $arSession): array
    {
        if ($arSession['point_id'] !== '' && self::isEmptyProperty($arPropertyList, self::SESSION_KEY_POINT_ID)) {
            $arPropertyList[self::SESSION_KEY_POINT_ID] = $arSession['point_id'];
            $arPropertyList[self::SESSION_KEY_POINT_NAME] = $arSession['point_name'];
            $arPropertyList[self::SESSION_KEY_POINT_ADDRESS] = $arSession['point_address'];
        }

        if ($arSession['product_code'] !== '' && self::isEmptyProperty($arPropertyList, self::SESSION_KEY_PRODUCT_CODE)) {
            $arPropertyList[self::SESSION_KEY_PRODUCT_CODE] = $arSession['product_code'];
            $arPropertyList[self::SESSION_KEY_PRODUCT_NAME] = $arSession['product_name'];
        }

        return $arPropertyList;
    }

    /**
     * Check whether an order property key is absent or blank.
     *
     * @param array<mixed, mixed> $arPropertyList
     */
    private static function isEmptyProperty(array $arPropertyList, string $sKey): bool
    {
        $mValue = $arPropertyList[$sKey] ?? '';

        return !is_string($mValue) || trim($mValue) === '';
    }

    /**
     * Read and trim a string value from Session.
     */
    private static function readSessionString(string $sKey): string
    {
        $mValue = Session::get($sKey, '');

        return is_string($mValue) ? trim($mValue) : '';
    }

    /**
     * Determine whether the order uses the PostNord shipping processor.
     */
    private static function isPostNordOrder(Order $obOrder): bool
    {
        /** @var mixed $mShippingType */
        $mShippingType = $obOrder->shipping_type;

        return $mShippingType instanceof ShippingType
            && $mShippingType->api_class === PostNordShippingProcessor::class;
    }
}
