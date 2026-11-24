<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Updates;

use Illuminate\Support\Facades\DB;
use Lovata\Toolbox\Models\CommonProperty;
use October\Rain\Database\Updates\Migration;

/**
 * Class FixOrderPropertiesWidgetType
 * @package Logingrupa\PostNordShippingShopaholic\Updates
 *
 * Lovata OrderProperty::getWidgetData() requires type='input' (CommonProperty::TYPE_INPUT)
 * and a tab inside settings JSON; the initial 1.0.0 migration inserted type='text' with
 * null settings, which caused getWidgetData() to return [] and hide the PostNord fields
 * from the backend Order form.
 */
class FixOrderPropertiesWidgetType extends Migration
{
    private const ORDER_PROPERTIES_TABLE = 'lovata_orders_shopaholic_addition_properties';
    private const SHIPPING_ADDRESS_TAB = 'lovata.ordersshopaholic::lang.tab.shipping_address';

    /** @var list<string> */
    private const CODE_LIST = [
        'postnord_service_point_id',
        'postnord_service_point_name',
        'postnord_service_point_address',
    ];

    public function up(): void
    {
        DB::table(self::ORDER_PROPERTIES_TABLE)
            ->whereIn('code', self::CODE_LIST)
            ->update([
                'active'   => true,
                'type'     => CommonProperty::TYPE_INPUT,
                'settings' => json_encode(['tab' => self::SHIPPING_ADDRESS_TAB], JSON_THROW_ON_ERROR),
            ]);
    }

    public function down(): void
    {
        DB::table(self::ORDER_PROPERTIES_TABLE)
            ->whereIn('code', self::CODE_LIST)
            ->update([
                'type'     => 'text',
                'settings' => null,
            ]);
    }
}
