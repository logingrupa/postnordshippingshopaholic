<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Updates;

use Illuminate\Support\Facades\DB;
use October\Rain\Database\Updates\Migration;

/**
 * Class AddProductOrderProperties
 * @package Logingrupa\PostNordShippingShopaholic\Updates
 *
 * Registers PostNord delivery product code & name order properties on the
 * Shipping address tab so warehouse/fulfillment knows whether the shopper
 * chose Parcel Locker (S), Service Point (Z), Home Small (B), or Home (H).
 */
class AddProductOrderProperties extends Migration
{
    private const ORDER_PROPERTIES_TABLE = 'lovata_orders_shopaholic_addition_properties';

    /** @var list<array{active: bool, code: string, name: string, type: string, settings: string, sort_order: int}> */
    private array $arPropertyList = [
        [
            'active'     => true,
            'code'       => 'postnord_product_code',
            'name'       => 'PostNord Product Code',
            'type'       => 'input',
            'settings'   => '{"tab":"lovata.ordersshopaholic::lang.tab.shipping_address"}',
            'sort_order' => 103,
        ],
        [
            'active'     => true,
            'code'       => 'postnord_product_name',
            'name'       => 'PostNord Delivery Method',
            'type'       => 'input',
            'settings'   => '{"tab":"lovata.ordersshopaholic::lang.tab.shipping_address"}',
            'sort_order' => 104,
        ],
    ];

    public function up(): void
    {
        DB::table(self::ORDER_PROPERTIES_TABLE)->insertOrIgnore($this->arPropertyList);
    }

    public function down(): void
    {
        $arCodeList = array_column($this->arPropertyList, 'code');

        DB::table(self::ORDER_PROPERTIES_TABLE)
            ->whereIn('code', $arCodeList)
            ->delete();
    }
}
