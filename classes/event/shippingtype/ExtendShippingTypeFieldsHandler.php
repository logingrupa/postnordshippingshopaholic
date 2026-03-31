<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType;

use Event;
use Lovata\OrdersShopaholic\Controllers\ShippingTypes;
use Lovata\OrdersShopaholic\Models\ShippingType;

/**
 * Class ExtendShippingTypeFieldsHandler
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType
 *
 * Adds a "Pickup Provider" dropdown to the ShippingType backend form.
 * Uses an event-driven pattern (like PaymentMethod gateway_id) so each
 * carrier plugin can register itself without needing its own DB column.
 */
class ExtendShippingTypeFieldsHandler
{
    /** Event fired to collect pickup provider options from all carrier plugins */
    public const EVENT_GET_PICKUP_PROVIDER_LIST = 'shopaholic.shipping_type.get_pickup_provider_list';

    /**
     * Register event listeners
     */
    public function subscribe($obDispatcher): void
    {
        $obDispatcher->listen(
            'backend.form.extendFields',
            [self::class, 'handleExtendFields']
        );

        $obDispatcher->listen(
            self::EVENT_GET_PICKUP_PROVIDER_LIST,
            [self::class, 'handleGetPickupProviderList']
        );
    }

    /**
     * Extend backend form fields for ShippingType
     *
     * @param \Backend\Widgets\Form $obFormWidget
     */
    public static function handleExtendFields(mixed $obFormWidget): void
    {
        if (!$obFormWidget->getController() instanceof ShippingTypes) {
            return;
        }

        if (!$obFormWidget->model instanceof ShippingType) {
            return;
        }

        $obFormWidget->addTabFields([
            'property[pickup_provider]' => [
                'label'       => 'logingrupa.postnordshippingshopaholic::lang.field.pickup_provider',
                'comment'     => 'logingrupa.postnordshippingshopaholic::lang.field.pickup_provider_comment',
                'type'        => 'dropdown',
                'emptyOption' => 'lovata.toolbox::lang.field.empty',
                'tab'         => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'options'     => self::getPickupProviderList(),
            ],
        ]);
    }

    /**
     * Register PostNord as a pickup provider option
     *
     * @return array<string, string>
     */
    public static function handleGetPickupProviderList(): array
    {
        return [
            'postnord' => 'PostNord',
        ];
    }

    /**
     * Collect pickup provider options from all registered plugins via event
     *
     * @return array<string, string>
     */
    private static function getPickupProviderList(): array
    {
        /** @var array<string, string> $arResult */
        $arResult = [];

        $mEventResult = Event::fire(self::EVENT_GET_PICKUP_PROVIDER_LIST);
        if (empty($mEventResult)) {
            return $arResult;
        }

        /** @var array<int, mixed> $arEventResult */
        $arEventResult = $mEventResult;

        foreach ($arEventResult as $arProviderList) {
            if (empty($arProviderList) || !is_array($arProviderList)) {
                continue;
            }

            /** @var array<string, string> $arProviderList */
            $arResult = array_merge($arResult, $arProviderList);
        }

        asort($arResult);

        return $arResult;
    }
}
