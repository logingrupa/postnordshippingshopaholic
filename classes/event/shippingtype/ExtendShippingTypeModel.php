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
 * Extends ShippingTypeItem to expose pickup_provider from the property JSON column.
 * No schema changes needed — uses the native ShippingType::$property JSON field.
 */
class ExtendShippingTypeModel
{
    public const PICKUP_PROVIDER_POSTNORD = 'postnord';

    /**
     * Register event listeners
     */
    public function subscribe(Dispatcher $obDispatcher): void
    {
        ShippingTypeItem::extend(function (ShippingTypeItem $obItem): void {
            $obItem->addDynamicMethod('getPickupProviderAttribute', function () use ($obItem): ?string {
                $arPropertyList = $obItem->getAttribute('property');
                if (!is_array($arPropertyList)) {
                    return null;
                }

                $mProvider = $arPropertyList['pickup_provider'] ?? null;

                return is_string($mProvider) && $mProvider !== '' ? $mProvider : null;
            });

            $obItem->addDynamicMethod('getIsPostnordAttribute', function () use ($obItem): bool {
                /** @var string|null $sProvider */
                $sProvider = $obItem->pickup_provider;

                return $sProvider === self::PICKUP_PROVIDER_POSTNORD;
            });
        });
    }

    /**
     * Check if a ShippingType model is PostNord pickup
     */
    public static function isPostNord(ShippingType $obShippingType): bool
    {
        return $obShippingType->getProperty('pickup_provider') === self::PICKUP_PROVIDER_POSTNORD;
    }
}
