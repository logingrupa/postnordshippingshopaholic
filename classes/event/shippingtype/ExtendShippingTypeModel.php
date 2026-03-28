<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType;

use Illuminate\Events\Dispatcher;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Models\ShippingType;

/**
 * Class ExtendShippingTypeModel
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType
 *
 * Extends the ShippingType model to add the is_postnord field as fillable
 * and extends ShippingTypeItem to cache the attribute.
 */
class ExtendShippingTypeModel
{
    /**
     * Register event listeners
     */
    public function subscribe(Dispatcher $obDispatcher): void
    {
        ShippingType::extend(function (ShippingType $obModel): void {
            $obModel->fillable[] = 'is_postnord';

            if (!in_array('is_postnord', $obModel->cached)) {
                $obModel->cached[] = 'is_postnord';
            }
        });

        ShippingTypeItem::extend(function (ShippingTypeItem $obItem): void {
            $obItem->addDynamicMethod('getIsPostnordAttribute', function () use ($obItem): bool {
                return (bool) $obItem->getAttribute('is_postnord');
            });
        });
    }
}
