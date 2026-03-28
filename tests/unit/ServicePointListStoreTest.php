<?php

declare(strict_types=1);

use Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint\ServicePointListStore;
use Lovata\Toolbox\Classes\Store\AbstractStoreWithParam;

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

    // Check it has Override attribute
    $arAttributeList = $obMethod->getAttributes(\Override::class);
    expect($arAttributeList)->toHaveCount(1);
});

it('returns empty array for empty postal code', function (): void {
    $obStore = ServicePointListStore::instance();
    $arResult = $obStore->get('');

    expect($arResult)->toBeArray()->toBeEmpty();
});
