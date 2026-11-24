<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint\ServicePointListStore;
use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;
use Lovata\OrdersShopaholic\Models\ShippingType;
use Lovata\Toolbox\Classes\Store\AbstractStoreWithParam;

uses(PostNordTestCase::class);

beforeEach(function (): void {
    Cache::flush();

    if (!Schema::hasTable('lovata_orders_shopaholic_shipping_types')) {
        Schema::create('lovata_orders_shopaholic_shipping_types', function (Blueprint $obTable): void {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->integer('sort_order')->default(0);
            $obTable->text('preview_text')->nullable();
            $obTable->decimal('price', 15, 2)->default(0);
            $obTable->string('api_class')->nullable();
            $obTable->text('property')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

    if (!Schema::hasTable('lovata_ordersshopaholic_shipping_restrictions')) {
        Schema::create('lovata_ordersshopaholic_shipping_restrictions', function (Blueprint $obTable): void {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('restriction')->nullable();
            $obTable->string('code')->nullable();
            $obTable->text('property')->nullable();
            $obTable->timestamps();
        });
    }

    if (!Schema::hasTable('lovata_ordersshopaholic_shipping_restrictions_link')) {
        Schema::create('lovata_ordersshopaholic_shipping_restrictions_link', function (Blueprint $obTable): void {
            $obTable->integer('shipping_type_id');
            $obTable->integer('shipping_restriction_id');
        });
    }

    if (!Schema::hasTable('logingrupa_postnord_service_points')) {
        Schema::create('logingrupa_postnord_service_points', function (Blueprint $obTable): void {
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
        });
    }
});

it('extends AbstractStoreWithParam', function (): void {
    expect(is_subclass_of(ServicePointListStore::class, AbstractStoreWithParam::class))
        ->toBeTrue();
});

it('has a singleton instance', function (): void {
    $obReflection = new ReflectionClass(ServicePointListStore::class);
    $obProperty = $obReflection->getProperty('instance');

    expect($obProperty->isProtected())->toBeTrue();
    expect($obProperty->isStatic())->toBeTrue();
});

it('implements getIDListFromDB method', function (): void {
    $obReflection = new ReflectionClass(ServicePointListStore::class);
    $obMethod = $obReflection->getMethod('getIDListFromDB');

    expect($obMethod->isProtected())->toBeTrue();

    $arAttributeList = $obMethod->getAttributes(\Override::class);
    expect($arAttributeList)->toHaveCount(1);
});

it('returns empty array for empty postal code', function (): void {
    $obStore = ServicePointListStore::instance();
    $arResult = $obStore->get('');

    expect($arResult)->toBeArray()->toBeEmpty();
});

it('persists neighboring-postcode points in distance order, skips malformed records, and isolates per-search distances', function (): void {
    $obShippingType = ShippingType::create([
        'active'    => true,
        'name'      => 'PostNord NO',
        'code'      => 'postnord-no',
        'api_class' => PostNordShippingProcessor::class,
        'property'  => [
            'postnord_api_mode'     => PostNordClient::MODE_NORDIC,
            'postnord_sandbox'      => false,
            'postnord_api_key'      => 'prod-key',
            'postnord_country_code' => 'NO',
            'postnord_max_results'  => 5,
        ],
    ]);

    Http::fake([
        'api2.postnord.com/rest/businesslocation/v5/servicepoints/nearest/byaddress*' => Http::sequence()
            ->push([
                'servicePointInformationResponse' => [
                    'servicePoints' => [
                        [
                            'servicePointId'  => 'SP-NEAR',
                            'name'            => 'Moss Locker',
                            'routeDistance'   => 489,
                            'visitingAddress' => [
                                'streetName'   => 'Kantarellstien',
                                'streetNumber' => '48',
                                'postalCode'   => '1529',
                                'city'         => 'MOSS',
                                'countryCode'  => '',
                            ],
                        ],
                        [
                            // Malformed point missing city — must be skipped, not throw
                            'servicePointId'  => 'SP-BROKEN',
                            'name'            => 'Broken Point',
                            'routeDistance'   => 600,
                            'visitingAddress' => [
                                'streetName' => 'Nowhere',
                                'postalCode' => '1528',
                                'city'       => '',
                            ],
                        ],
                        [
                            'servicePointId'  => 'SP-FAR',
                            'name'            => 'Varnaveien Locker',
                            'routeDistance'   => 808,
                            'visitingAddress' => [
                                'streetName'   => 'Varnaveien',
                                'streetNumber' => '43E',
                                'postalCode'   => '1526',
                                'city'         => 'MOSS',
                                'countryCode'  => 'NO',
                            ],
                        ],
                    ],
                ],
            ], 200)
            ->push([
                'servicePointInformationResponse' => [
                    'servicePoints' => [
                        [
                            'servicePointId'  => 'SP-NEAR',
                            'name'            => 'Moss Locker',
                            'routeDistance'   => 2500,
                            'visitingAddress' => [
                                'streetName'   => 'Kantarellstien',
                                'streetNumber' => '48',
                                'postalCode'   => '1529',
                                'city'         => 'MOSS',
                                'countryCode'  => 'NO',
                            ],
                        ],
                    ],
                ],
            ], 200),
    ]);

    $obStore = ServicePointListStore::instance();
    $arSearch1528 = $obStore->getSearchResults('1528', (int) $obShippingType->id);

    expect($arSearch1528)->toHaveCount(2);
    expect($arSearch1528[0]['distance_in_meters'])->toBe(489);
    expect($arSearch1528[1]['distance_in_meters'])->toBe(808);

    // Second search for another postal code returns a different distance for the same point
    $arSearch1530 = $obStore->getSearchResults('1530', (int) $obShippingType->id);
    expect($arSearch1530)->toHaveCount(1);
    expect($arSearch1530[0]['distance_in_meters'])->toBe(2500);

    // Re-reading cached 1528 search preserves 1528's own distances (489, 808)
    $arSearch1528Cached = $obStore->getSearchResults('1528', (int) $obShippingType->id);
    expect($arSearch1528Cached[0]['distance_in_meters'])->toBe(489);
    expect($arSearch1528Cached[1]['distance_in_meters'])->toBe(808);
});

it('isolates cache keys across countries and sandbox vs production, and never caches DB fallback', function (): void {
    $obNoShipping = ShippingType::create([
        'active'    => true,
        'name'      => 'PostNord NO',
        'code'      => 'postnord-no',
        'api_class' => PostNordShippingProcessor::class,
        'property'  => [
            'postnord_api_mode'     => PostNordClient::MODE_NORDIC,
            'postnord_sandbox'      => false,
            'postnord_api_key'      => 'no-key',
            'postnord_country_code' => 'NO',
        ],
    ]);

    $obDkShipping = ShippingType::create([
        'active'    => true,
        'name'      => 'PostNord DK',
        'code'      => 'postnord-dk',
        'api_class' => PostNordShippingProcessor::class,
        'property'  => [
            'postnord_api_mode'     => PostNordClient::MODE_NORDIC,
            'postnord_sandbox'      => false,
            'postnord_api_key'      => 'dk-key',
            'postnord_country_code' => 'DK',
        ],
    ]);

    // Seed an existing NO fallback row for postal code 1000
    ServicePoint::create([
        'service_point_id' => 'LOCAL-NO-1000',
        'name'             => 'Cached Oslo Point',
        'street_name'      => 'Oslo St',
        'postal_code'      => '1000',
        'city'             => 'OSLO',
        'country_code'     => 'NO',
    ]);

    Http::fake([
        'api2.postnord.com/*' => Http::sequence()
            ->push('Upstream 500', 500) // NO fails -> uses NO fallback, must NOT cache!
            ->push([
                'servicePointInformationResponse' => [
                    'servicePoints' => [
                        [
                            'servicePointId'  => 'API-NO-1000',
                            'name'            => 'Fresh Oslo Point',
                            'routeDistance'   => 300,
                            'visitingAddress' => [
                                'streetName'  => 'Fresh St',
                                'postalCode'  => '1000',
                                'city'        => 'OSLO',
                                'countryCode' => 'NO',
                            ],
                        ],
                    ],
                ],
            ], 200) // NO recovers -> gets fresh API point!
            ->push([
                'servicePointInformationResponse' => [
                    'servicePoints' => [
                        [
                            'servicePointId'  => 'API-DK-1000',
                            'name'            => 'Copenhagen Point',
                            'routeDistance'   => 200,
                            'visitingAddress' => [
                                'streetName'  => 'Bredgade 1',
                                'postalCode'  => '1000',
                                'city'        => 'KOBENHAVN',
                                'countryCode' => 'DK',
                            ],
                        ],
                    ],
                ],
            ], 200), // DK 1000 does not collide with NO 1000!
    ]);

    $obStore = ServicePointListStore::instance();

    // 1. API error returns country-scoped DB fallback
    $arFallback = $obStore->getSearchResults('1000', (int) $obNoShipping->id);
    expect($arFallback)->toHaveCount(1);
    expect(ServicePoint::find($arFallback[0]['id'])?->service_point_id)->toBe('LOCAL-NO-1000');

    // 2. Fallback was NOT cached, so next call fetches recovered API point
    $arRecovered = $obStore->getSearchResults('1000', (int) $obNoShipping->id);
    expect($arRecovered)->toHaveCount(1);
    expect(ServicePoint::find($arRecovered[0]['id'])?->service_point_id)->toBe('API-NO-1000');

    // 3. DK 1000 does NOT collide with NO 1000
    $arDk = $obStore->getSearchResults('1000', (int) $obDkShipping->id);
    expect($arDk)->toHaveCount(1);
    expect(ServicePoint::find($arDk[0]['id'])?->service_point_id)->toBe('API-DK-1000');
});
