<?php namespace Logingrupa\PostNordShippingShopaholic\Updates;

use October\Rain\Database\Updates\Migration;

/**
 * Class ExtendShippingTypesTable
 * @package Logingrupa\PostNordShippingShopaholic\Updates
 *
 * This migration is intentionally empty.
 *
 * PostNord identification uses the native ShippingType::$property JSON column
 * (property[pickup_provider] = 'postnord') instead of a dedicated boolean column.
 * This follows the same pattern as PaymentMethod::$gateway_id for payment gateways.
 *
 * If upgrading from v1.0.0 where is_postnord column was added, run:
 *   php artisan tinker --execute="Schema::table('lovata_orders_shopaholic_shipping_types', fn(\$t) => \$t->dropColumn('is_postnord'))"
 */
class ExtendShippingTypesTable extends Migration
{
    public function up()
    {
        // No schema changes needed — uses native ShippingType::$property JSON column
    }

    public function down()
    {
        // Nothing to rollback
    }
}
