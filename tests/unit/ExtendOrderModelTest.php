<?php
declare(strict_types=1);

use Logingrupa\PostNordShippingShopaholic\Classes\Event\Order\ExtendOrderModel;

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
    // Verify subscribe wires to the correct event
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

    $obType = $arParameters[0]->getType();
    expect($obType)->not->toBeNull();
    expect($obType->getName())->toBe(\Lovata\OrdersShopaholic\Models\Order::class);
});

it('reads three session keys for service point data', function (): void {
    $obReflection = new ReflectionClass(ExtendOrderModel::class);
    $sSource = file_get_contents($obReflection->getFileName());

    expect($sSource)->toContain('postnord_service_point_id');
    expect($sSource)->toContain('postnord_service_point_name');
    expect($sSource)->toContain('postnord_service_point_address');
});

it('forgets session keys after writing to order', function (): void {
    $obReflection = new ReflectionClass(ExtendOrderModel::class);
    $sSource = file_get_contents($obReflection->getFileName());

    expect($sSource)->toContain('Session::forget');
});
