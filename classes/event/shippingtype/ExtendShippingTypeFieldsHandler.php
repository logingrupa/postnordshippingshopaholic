<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType;

use Illuminate\Events\Dispatcher;
use Lovata\OrdersShopaholic\Controllers\ShippingTypes;
use Lovata\OrdersShopaholic\Models\ShippingType;

/**
 * Class ExtendShippingTypeFieldsHandler
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType
 *
 * Adds the "Is PostNord Pickup" checkbox to the ShippingType backend form.
 */
class ExtendShippingTypeFieldsHandler
{
    /**
     * Register event listeners
     */
    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen(
            'backend.form.extendFields',
            [self::class, 'handleExtendFields']
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

        $obFormWidget->addFields([
            'is_postnord' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.is_postnord',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.is_postnord_comment',
                'type'    => 'checkbox',
                'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_postnord',
            ],
        ]);
    }
}
