<?php
declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordPriceCalculator;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordSessionQuoteStore;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Interfaces\ShippingPriceProcessorInterface;
use Lovata\OrdersShopaholic\Models\ShippingType;
use Lovata\Shopaholic\Models\Currency;

uses(PostNordTestCase::class);

beforeEach(function (): void {
    Cache::flush();
    PostNordSessionQuoteStore::clearSessionQuotes();

    if (!Schema::hasTable('lovata_shopaholic_currency')) {
        Schema::create('lovata_shopaholic_currency', function (Blueprint $obTable): void {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->boolean('is_default')->default(false);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('symbol')->nullable();
            $obTable->decimal('rate', 15, 4)->default(1);
            $obTable->integer('sort_order')->default(0);
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

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

it('implements ShippingPriceProcessorInterface', function (): void {
    $arInterfaces = class_implements(PostNordShippingProcessor::class);
    expect($arInterfaces)->toBeArray();
    expect($arInterfaces)->toHaveKey(ShippingPriceProcessorInterface::class);
});

it('returns fields array with expected keys from getFields', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    expect($arFields)->toBeArray();
    expect($arFields)->toHaveKey('postnord_info');
    expect($arFields)->toHaveKey('property[postnord_api_mode]');
    expect($arFields)->toHaveKey('property[postnord_sandbox]');
    expect($arFields)->toHaveKey('property[postnord_client_id]');
    expect($arFields)->toHaveKey('property[postnord_client_secret]');
    expect($arFields)->toHaveKey('property[postnord_customer_id]');
    expect($arFields)->toHaveKey('property[postnord_sender_postal_code]');
    expect($arFields)->toHaveKey('property[postnord_dynamic_pricing]');
    expect($arFields)->toHaveKey('property[postnord_product_filter]');
    expect($arFields)->toHaveKey('property[postnord_default_weight_grams]');
    expect($arFields)->toHaveKey('property[postnord_vat_percent]');
    expect($arFields)->toHaveKey('property[postnord_price_markup]');
    expect($arFields)->toHaveKey('property[postnord_price_rounding]');
    expect($arFields)->toHaveKey('property[postnord_api_key]');
    expect($arFields)->toHaveKey('property[postnord_country_code]');
    expect($arFields)->toHaveKey('property[postnord_max_results]');
});

it('returns section type for postnord_info field', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    expect($arFields['postnord_info']['type'])->toBe('section');
    expect($arFields['postnord_info']['span'])->toBe('full');
});

it('returns sensitive type for api_key and client_secret fields', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    $arApiKeyField = $arFields['property[postnord_api_key]'];
    expect($arApiKeyField['type'])->toBe('sensitive');
    expect($arApiKeyField['required'])->toBeFalse();

    $arClientSecretField = $arFields['property[postnord_client_secret]'];
    expect($arClientSecretField['type'])->toBe('sensitive');
});

it('returns dropdown type for country_code field with correct options', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    $arCountryField = $arFields['property[postnord_country_code]'];
    expect($arCountryField['type'])->toBe('dropdown');
    expect($arCountryField['default'])->toBe('NO');
    expect($arCountryField['options'])->toBe([
        'NO' => 'Norway',
        'SE' => 'Sweden',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'LV' => 'Latvia',
        'LT' => 'Lithuania',
    ]);
});

it('returns number type for max_results field with default 10', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    $arMaxResultsField = $arFields['property[postnord_max_results]'];
    expect($arMaxResultsField['type'])->toBe('number');
    expect($arMaxResultsField['default'])->toBe(10);
});

it('includes trigger conditions referencing the processor class on all fields', function (): void {
    $arFields = PostNordShippingProcessor::getFields();
    $sExpectedCondition = 'value[' . PostNordShippingProcessor::class . ']';

    foreach ($arFields as $arFieldConfig) {
        expect($arFieldConfig)->toHaveKey('trigger');
        expect($arFieldConfig['trigger']['action'])->toBe('show');
        expect($arFieldConfig['trigger']['field'])->toBe('api_class');
        expect($arFieldConfig['trigger']['condition'])->toBe($sExpectedCondition);
    }
});

it('returns true from validate', function (): void {
    $obMockItem = Mockery::mock(ShippingTypeItem::class);
    $obMockItem->shouldIgnoreMissing();
    $obProcessor = new PostNordShippingProcessor($obMockItem);

    expect($obProcessor->validate())->toBeTrue();
});

it('returns price_full value from getPrice', function (): void {
    $obMockItem = Mockery::mock(ShippingTypeItem::class);
    $obMockItem->shouldIgnoreMissing();
    $obMockItem->shouldReceive('extendableGet')
        ->with('price_full')
        ->andReturn(99.50);

    $obProcessor = new PostNordShippingProcessor($obMockItem);

    expect($obProcessor->getPrice())->toBe(99.50);
});

it('returns zero when price_full is null', function (): void {
    $obMockItem = Mockery::mock(ShippingTypeItem::class);
    $obMockItem->shouldIgnoreMissing();
    $obMockItem->shouldReceive('extendableGet')
        ->with('price_full')
        ->andReturn(null);

    $obProcessor = new PostNordShippingProcessor($obMockItem);

    expect($obProcessor->getPrice())->toBe(0.0);
});

it('returns empty string from getMessage', function (): void {
    $obMockItem = Mockery::mock(ShippingTypeItem::class);
    $obMockItem->shouldIgnoreMissing();
    $obProcessor = new PostNordShippingProcessor($obMockItem);

    expect($obProcessor->getMessage())->toBe('');
});

it('ignores session quotes when dynamic pricing is disabled', function (): void {
    PostNordSessionQuoteStore::storePointQuotes(55, '0150', 100, [
        '6412472' => [
            'price_default' => 104.0,
            'price_net'     => 83.0,
            'currency'      => 'NOK',
            'product_code'  => 'S',
        ],
    ]);
    PostNordSessionQuoteStore::selectPointQuote(55, '6412472');

    $obMockItem = Mockery::mock(ShippingTypeItem::class);
    $obMockItem->shouldIgnoreMissing();
    $obMockItem->shouldReceive('extendableGet')->with('price_full')->andReturn(99.0);
    $obMockItem->shouldReceive('extendableGet')->with('id')->andReturn(55);
    $obMockItem->shouldReceive('extendableGet')->with('property')->andReturn([
        'postnord_api_mode'        => PostNordClient::MODE_NORWAY,
        'postnord_dynamic_pricing' => false,
    ]);

    $obProcessor = new PostNordShippingProcessor($obMockItem);
    expect($obProcessor->getPrice())->toBe(99.0);
});

it('returns server-quoted price when dynamic pricing is enabled and selected weight matches cart', function (): void {
    // When cart tables are absent in unit tests, calculateCurrentCartWeightGrams falls back to default weight (500g)
    PostNordSessionQuoteStore::storePointQuotes(55, '0150', PostNordPriceCalculator::DEFAULT_ITEM_WEIGHT_GRAMS, [
        '6412472' => [
            'price_default' => 104.0,
            'price_net'     => 83.0,
            'currency'      => 'NOK',
            'product_code'  => 'S',
        ],
    ]);
    PostNordSessionQuoteStore::selectPointQuote(55, '6412472');

    $obMockItem = Mockery::mock(ShippingTypeItem::class);
    $obMockItem->shouldIgnoreMissing();
    $obMockItem->shouldReceive('extendableGet')->with('price_full')->andReturn(99.0);
    $obMockItem->shouldReceive('extendableGet')->with('id')->andReturn(55);
    $obMockItem->shouldReceive('extendableGet')->with('property')->andReturn([
        'postnord_api_mode'        => PostNordClient::MODE_NORWAY,
        'postnord_dynamic_pricing' => true,
    ]);

    $obProcessor = new PostNordShippingProcessor($obMockItem);
    expect($obProcessor->getPrice())->toBe(104.0);
});

it('re-quotes automatically when cart weight changed after pickup point selection', function (): void {
    Currency::query()->delete();
    Currency::create([
        'active'     => true,
        'is_default' => true,
        'name'       => 'Norwegian Krone',
        'code'       => 'NOK',
        'symbol'     => 'kr',
        'rate'       => 1.0,
    ]);

    $obShippingType = ShippingType::create([
        'active'    => true,
        'name'      => 'PostNord Dynamic Test',
        'code'      => 'postnord-dynamic-test',
        'price'     => 99,
        'api_class' => PostNordShippingProcessor::class,
        'property'  => [
            'postnord_api_mode'             => PostNordClient::MODE_NORWAY,
            'postnord_sandbox'              => true,
            'postnord_client_id'            => 'cid',
            'postnord_client_secret'        => 'csec',
            'postnord_customer_id'          => '50076769',
            'postnord_sender_postal_code'   => '1528',
            'postnord_dynamic_pricing'      => true,
            'postnord_vat_percent'          => 25,
            'postnord_price_markup'         => 0,
            'postnord_price_rounding'       => PostNordPriceCalculator::ROUND_CEIL,
            'postnord_default_weight_grams' => 500,
        ],
    ]);

    // Stored quote was for 2000g, but current empty cart is 100g -> triggers automatic refresh
    PostNordSessionQuoteStore::storePointQuotes((int) $obShippingType->id, '0150', 2000, [
        '6412472' => [
            'price_default' => 149.0,
            'price_net'     => 119.0,
            'currency'      => 'NOK',
            'product_code'  => 'S',
        ],
    ]);
    PostNordSessionQuoteStore::selectPointQuote((int) $obShippingType->id, '6412472');

    Http::fake([
        'auth.postnord.no/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300], 200),
        'atapi2.postnord.no/rest/api/products' => Http::response([
            'products' => [
                [
                    'productCode' => 'S',
                    'price'       => [
                        'priceBreakdown' => [
                            'netAmount' => ['value' => 83.0, 'currency' => 'NOK'],
                        ],
                    ],
                    'serviceLocations' => [
                        [
                            'id'                => '6412472',
                            'name'              => 'PAKKEAUTOMAT OSLO S',
                            'addressComponents' => [
                                'streetAddress' => 'JERNBANETORGET 1',
                                'postalCode'    => '0154',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'distance' => 200,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $obItem = ShippingTypeItem::make((int) $obShippingType->id);
    $obProcessor = new PostNordShippingProcessor($obItem);

    // 83 * 1.25 = 103.75 -> ceil = 104.0 NOK
    expect($obProcessor->getPrice())->toBe(104.0);
    expect(PostNordSessionQuoteStore::getActiveSelectedQuote((int) $obShippingType->id)['weight_grams'] ?? null)
        ->toBe(PostNordPriceCalculator::DEFAULT_ITEM_WEIGHT_GRAMS);
});
