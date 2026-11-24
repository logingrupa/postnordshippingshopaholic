<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Logingrupa\PostNordShippingShopaholic\Classes\Event\Order\ExtendOrderModel;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\ShippingType;

uses(PostNordTestCase::class);

beforeEach(function (): void {
    if (!Schema::hasTable('lovata_orders_shopaholic_shipping_types')) {
        Schema::create('lovata_orders_shopaholic_shipping_types', function (Blueprint $obTable): void {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->integer('sort_order')->default(0);
            $obTable->string('api_class')->nullable();
            $obTable->text('property')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

    if (!Schema::hasTable('lovata_orders_shopaholic_orders')) {
        Schema::create('lovata_orders_shopaholic_orders', function (Blueprint $obTable): void {
            $obTable->increments('id');
            $obTable->string('order_number')->nullable();
            $obTable->string('secret_key')->nullable();
            $obTable->integer('shipping_type_id')->nullable();
            $obTable->text('property')->nullable();
            $obTable->timestamps();
        });
    }
});

it('has subscribe method', function (): void {
    $obReflection = new ReflectionClass(ExtendOrderModel::class);
    $obMethod = $obReflection->getMethod('subscribe');

    expect($obMethod->isPublic())->toBeTrue();
});

it('has handleAfterCreate static method', function (): void {
    $obReflection = new ReflectionClass(ExtendOrderModel::class);
    $obMethod = $obReflection->getMethod('handleAfterCreate');

    expect($obMethod->isStatic())->toBeTrue();
    expect($obMethod->isPublic())->toBeTrue();
});

it('defines session key constants', function (): void {
    $obReflection = new ReflectionClass(ExtendOrderModel::class);

    $obPointIdConst = $obReflection->getReflectionConstant('SESSION_KEY_POINT_ID');
    $obPointNameConst = $obReflection->getReflectionConstant('SESSION_KEY_POINT_NAME');
    $obPointAddressConst = $obReflection->getReflectionConstant('SESSION_KEY_POINT_ADDRESS');

    expect($obPointIdConst)->not->toBeFalse();
    expect($obPointNameConst)->not->toBeFalse();
    expect($obPointAddressConst)->not->toBeFalse();

    expect($obPointIdConst->getValue())->toBe('postnord_service_point_id');
    expect($obPointNameConst->getValue())->toBe('postnord_service_point_name');
    expect($obPointAddressConst->getValue())->toBe('postnord_service_point_address');
});

it('listens to shopaholic.order.after_create event', function (): void {
    $obReflection = new ReflectionClass(ExtendOrderModel::class);
    $sSource = file_get_contents($obReflection->getFileName());

    expect($sSource)->toContain('shopaholic.order.after_create');
    expect($sSource)->toContain('handleAfterCreate');
});

it('accepts Order parameter in handleAfterCreate', function (): void {
    $obReflection = new ReflectionClass(ExtendOrderModel::class);
    $obMethod = $obReflection->getMethod('handleAfterCreate');
    $arParameters = $obMethod->getParameters();

    expect($arParameters)->toHaveCount(1);
    expect($arParameters[0]->getName())->toBe('obOrder');

    $obType = $obParametersType = $arParameters[0]->getType();
    expect($obType)->not->toBeNull();
    expect($obType->getName())->toBe(Order::class);
});

it('does not write stale PostNord session data onto a non-PostNord order and clears session', function (): void {
    Session::put('postnord_service_point_id', '2055887');
    Session::put('postnord_service_point_name', 'PAKKEAUTOMAT KANTARELLSTIEN');
    Session::put('postnord_service_point_address', 'KANTARELLSTIEN 48, 1529 MOSS');

    $obShippingType = ShippingType::create([
        'name'      => 'DPD Locker',
        'code'      => 'dpd-latvia',
        'api_class' => '',
    ]);

    $obOrder = new Order();
    $obOrder->shipping_type_id = $obShippingType->id;
    $obOrder->property = ['account_type' => 'private'];
    $obOrder->save();
    $obOrder->setRelation('shipping_type', $obShippingType);

    ExtendOrderModel::handleAfterCreate($obOrder);

    $obReloaded = Order::find($obOrder->id);
    expect($obReloaded?->property)->toBe(['account_type' => 'private']);
    expect(Session::get('postnord_service_point_id'))->toBeNull();
    expect(Session::get('postnord_service_point_name'))->toBeNull();
    expect(Session::get('postnord_service_point_address'))->toBeNull();
});

it('does not overwrite form-submitted PostNord properties with stale session values', function (): void {
    Session::put('postnord_service_point_id', 'OLD-SESSION-ID');
    Session::put('postnord_service_point_name', 'Old Stale Point');
    Session::put('postnord_service_point_address', 'Old Stale Street');

    $obShippingType = ShippingType::create([
        'name'      => 'PostNord NO',
        'code'      => 'postnord',
        'api_class' => PostNordShippingProcessor::class,
    ]);

    $obOrder = new Order();
    $obOrder->shipping_type_id = $obShippingType->id;
    $obOrder->property = [
        'postnord_service_point_id'      => 'FRESH-FORM-ID',
        'postnord_service_point_name'    => 'Fresh Form Point',
        'postnord_service_point_address' => 'Fresh Form Street',
    ];
    $obOrder->save();
    $obOrder->setRelation('shipping_type', $obShippingType);

    ExtendOrderModel::handleAfterCreate($obOrder);

    $obReloaded = Order::find($obOrder->id);
    expect($obReloaded?->property['postnord_service_point_id'])->toBe('FRESH-FORM-ID');
    expect($obReloaded?->property['postnord_service_point_name'])->toBe('Fresh Form Point');
    expect(Session::get('postnord_service_point_id'))->toBeNull();
});

it('writes session fallback onto a PostNord order when form did not send pickup properties', function (): void {
    Session::put('postnord_service_point_id', '2055887');
    Session::put('postnord_service_point_name', 'PAKKEAUTOMAT KANTARELLSTIEN');
    Session::put('postnord_service_point_address', 'KANTARELLSTIEN 48, 1529 MOSS');

    $obShippingType = ShippingType::create([
        'name'      => 'PostNord NO',
        'code'      => 'postnord',
        'api_class' => PostNordShippingProcessor::class,
    ]);

    $obOrder = new Order();
    $obOrder->shipping_type_id = $obShippingType->id;
    $obOrder->property = ['account_type' => 'private'];
    $obOrder->save();
    $obOrder->setRelation('shipping_type', $obShippingType);

    ExtendOrderModel::handleAfterCreate($obOrder);

    $obReloaded = Order::find($obOrder->id);
    expect($obReloaded?->property)->toMatchArray([
        'account_type'                   => 'private',
        'postnord_service_point_id'      => '2055887',
        'postnord_service_point_name'    => 'PAKKEAUTOMAT KANTARELLSTIEN',
        'postnord_service_point_address' => 'KANTARELLSTIEN 48, 1529 MOSS',
    ]);
    expect(Session::get('postnord_service_point_id'))->toBeNull();
});
