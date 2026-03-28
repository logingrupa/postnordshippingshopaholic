<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\Order;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Session;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Class ExtendOrderModel
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\Order
 *
 * Writes the selected PostNord service point data from session
 * into the Order model's property array after order creation.
 */
class ExtendOrderModel
{
    private const SESSION_KEY_POINT_ID = 'postnord_service_point_id';
    private const SESSION_KEY_POINT_NAME = 'postnord_service_point_name';
    private const SESSION_KEY_POINT_ADDRESS = 'postnord_service_point_address';

    /**
     * Register event listeners
     */
    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen(
            'shopaholic.order.after_create',
            [self::class, 'handleAfterCreate']
        );
    }

    /**
     * Handle order after create event
     *
     * @param Order $obOrder The created order
     */
    public static function handleAfterCreate(Order $obOrder): void
    {
        $mServicePointId = Session::get(self::SESSION_KEY_POINT_ID);

        if (empty($mServicePointId)) {
            return;
        }

        $mServicePointName = Session::get(self::SESSION_KEY_POINT_NAME, '');
        $mServicePointAddress = Session::get(self::SESSION_KEY_POINT_ADDRESS, '');

        $sServicePointName = is_string($mServicePointName) ? $mServicePointName : '';
        $sServicePointAddress = is_string($mServicePointAddress) ? $mServicePointAddress : '';
        $sServicePointId = is_string($mServicePointId) ? $mServicePointId : '';

        /** @var mixed $mPropertyList */
        $mPropertyList = $obOrder->property;
        $arPropertyList = is_array($mPropertyList) ? $mPropertyList : [];

        $arPropertyList[self::SESSION_KEY_POINT_ID] = $sServicePointId;
        $arPropertyList[self::SESSION_KEY_POINT_NAME] = $sServicePointName;
        $arPropertyList[self::SESSION_KEY_POINT_ADDRESS] = $sServicePointAddress;

        $obOrder->property = $arPropertyList;
        $obOrder->save();

        Session::forget([
            self::SESSION_KEY_POINT_ID,
            self::SESSION_KEY_POINT_NAME,
            self::SESSION_KEY_POINT_ADDRESS,
        ]);
    }
}
