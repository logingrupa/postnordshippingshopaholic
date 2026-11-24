<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\Order;

use Event;
use Illuminate\Support\Facades\Session;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\ShippingType;

/**
 * Class ExtendOrderModel
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\Order
 *
 * Writes the selected PostNord service point data from session
 * into the Order model's property array after order creation when the order
 * uses PostNordShippingProcessor and the checkout form did not already supply it.
 */
class ExtendOrderModel
{
    private const SESSION_KEY_POINT_ID = 'postnord_service_point_id';
    private const SESSION_KEY_POINT_NAME = 'postnord_service_point_name';
    private const SESSION_KEY_POINT_ADDRESS = 'postnord_service_point_address';

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
    }

    /**
     * Handle order after create event.
     *
     * Always clears PostNord session keys so stale picks cannot leak onto future
     * orders. Only writes session fallback data when the order actually uses
     * PostNordShippingProcessor and does not already carry postnord_service_point_id
     * from the submitted checkout form.
     *
     * @param Order $obOrder The created order
     */
    public static function handleAfterCreate(Order $obOrder): void
    {
        $mServicePointId = Session::get(self::SESSION_KEY_POINT_ID);
        $mServicePointName = Session::get(self::SESSION_KEY_POINT_NAME, '');
        $mServicePointAddress = Session::get(self::SESSION_KEY_POINT_ADDRESS, '');

        Session::forget([
            self::SESSION_KEY_POINT_ID,
            self::SESSION_KEY_POINT_NAME,
            self::SESSION_KEY_POINT_ADDRESS,
        ]);

        if (!is_string($mServicePointId) || trim($mServicePointId) === '') {
            return;
        }

        if (!self::isPostNordOrder($obOrder)) {
            return;
        }

        /** @var mixed $mPropertyList */
        $mPropertyList = $obOrder->property;
        $arPropertyList = is_array($mPropertyList) ? $mPropertyList : [];

        $mExistingPointId = $arPropertyList[self::SESSION_KEY_POINT_ID] ?? '';
        if (is_string($mExistingPointId) && trim($mExistingPointId) !== '') {
            return;
        }

        $arPropertyList[self::SESSION_KEY_POINT_ID] = trim($mServicePointId);
        $arPropertyList[self::SESSION_KEY_POINT_NAME] = is_string($mServicePointName) ? trim($mServicePointName) : '';
        $arPropertyList[self::SESSION_KEY_POINT_ADDRESS] = is_string($mServicePointAddress) ? trim($mServicePointAddress) : '';

        $obOrder->property = $arPropertyList;
        $obOrder->save();
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
