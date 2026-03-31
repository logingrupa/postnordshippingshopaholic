<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType;

use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Models\ShippingType;

/**
 * Class ExtendShippingTypeModel
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType
 *
 * Registers PostNordShippingProcessor in the api_class dropdown and extends
 * ShippingTypeItem to expose an is_postnord convenience property.
 */
class ExtendShippingTypeModel
{
    /**
     * Register event listeners
     *
     * @param \Illuminate\Events\Dispatcher $obDispatcher
     */
    public function subscribe($obDispatcher): void
    {
        $obDispatcher->listen(
            ShippingType::EVENT_GET_SHIPPING_TYPE_API_CLASS_LIST,
            [self::class, 'handleGetApiClassList']
        );

        ShippingTypeItem::extend(function (ShippingTypeItem $obItem): void {
            $obItem->addDynamicMethod('getIsPostnordAttribute', function () use ($obItem): bool {
                $sApiClass = $obItem->getAttribute('api_class');

                return $sApiClass === PostNordShippingProcessor::class;
            });
        });
    }

    /**
     * Register PostNord as an available shipping API class option.
     *
     * @return array<class-string, string>
     */
    public static function handleGetApiClassList(): array
    {
        return [
            PostNordShippingProcessor::class => 'PostNord Pickup',
        ];
    }
}
