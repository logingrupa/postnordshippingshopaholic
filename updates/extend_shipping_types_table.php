<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class ExtendShippingTypesTable extends Migration
{
    public function up()
    {
        Schema::table('lovata_orders_shopaholic_shipping_types', function (Blueprint $obTable) {
            $obTable->boolean('is_postnord')->default(false);
        });
    }

    public function down()
    {
        if (Schema::hasColumn('lovata_orders_shopaholic_shipping_types', 'is_postnord')) {
            Schema::table('lovata_orders_shopaholic_shipping_types', function (Blueprint $obTable) {
                $obTable->dropColumn('is_postnord');
            });
        }
    }
}
