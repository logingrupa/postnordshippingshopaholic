<?php namespace Logingrupa\PostNordShippingShopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateServicePointsTable
 * @package Logingrupa\PostNordShippingShopaholic\Updates
 */
class CreateServicePointsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('logingrupa_postnord_service_points')) {
            return;
        }

        Schema::create('logingrupa_postnord_service_points', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('service_point_id')->index();
            $obTable->string('name');
            $obTable->string('street_name');
            $obTable->string('street_number')->nullable();
            $obTable->string('postal_code')->index();
            $obTable->string('city');
            $obTable->string('country_code', 2);
            $obTable->decimal('northing', 10, 7)->nullable();
            $obTable->decimal('easting', 10, 7)->nullable();
            $obTable->unsignedInteger('distance_in_meters')->nullable();
            $obTable->timestamps();

            $obTable->index(['postal_code', 'country_code']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('logingrupa_postnord_service_points');
    }
}
