<?php namespace Logingrupa\PostNordShippingShopaholic\Updates;

use Illuminate\Support\Facades\DB;
use October\Rain\Database\Updates\Migration;

/**
 * Class CreateOrderProperties
 * @package Logingrupa\PostNordShippingShopaholic\Updates
 */
class CreateOrderProperties extends Migration
{
    private const ORDER_PROPERTIES_TABLE = 'lovata_orders_shopaholic_addition_properties';

    /** @var list<array{code: string, name: string, type: string, sort_order: int}> */
    private array $arPropertyList = [
        [
            'code'       => 'postnord_service_point_id',
            'name'       => 'PostNord Service Point ID',
            'type'       => 'text',
            'sort_order' => 100,
        ],
        [
            'code'       => 'postnord_service_point_name',
            'name'       => 'PostNord Service Point Name',
            'type'       => 'text',
            'sort_order' => 101,
        ],
        [
            'code'       => 'postnord_service_point_address',
            'name'       => 'PostNord Service Point Address',
            'type'       => 'text',
            'sort_order' => 102,
        ],
    ];

    public function up()
    {
        DB::table(self::ORDER_PROPERTIES_TABLE)->insertOrIgnore($this->arPropertyList);
    }

    public function down()
    {
        $arCodeList = array_column($this->arPropertyList, 'code');

        DB::table(self::ORDER_PROPERTIES_TABLE)
            ->whereIn('code', $arCodeList)
            ->delete();
    }
}
