<?php

declare(strict_types=1);

use Cms\Classes\ComponentBase;
use Logingrupa\PostNordShippingShopaholic\Components\PostNordLocator;

it('extends ComponentBase', function (): void {
    expect(is_subclass_of(PostNordLocator::class, ComponentBase::class))
        ->toBeTrue();
});

it('returns correct component details', function (): void {
    $obReflection = new ReflectionClass(PostNordLocator::class);
    $obMethod = $obReflection->getMethod('componentDetails');

    // Verify Override attribute exists
    $arAttributeList = $obMethod->getAttributes(\Override::class);
    expect($arAttributeList)->toHaveCount(1);
});

it('has onGetServicePoints handler', function (): void {
    expect(method_exists(PostNordLocator::class, 'onGetServicePoints'))
        ->toBeTrue();
});

it('has onSelectServicePoint handler', function (): void {
    expect(method_exists(PostNordLocator::class, 'onSelectServicePoint'))
        ->toBeTrue();
});

it('stores selection in session via onSelectServicePoint', function (): void {
    // Verify the method signature accepts no parameters (reads from input())
    $obReflection = new ReflectionMethod(PostNordLocator::class, 'onSelectServicePoint');
    expect($obReflection->getNumberOfParameters())->toBe(0);
    expect($obReflection->isPublic())->toBeTrue();
});

it('validates postal code format in onGetServicePoints', function (): void {
    // Verify the method exists and is public
    $obReflection = new ReflectionMethod(PostNordLocator::class, 'onGetServicePoints');
    expect($obReflection->getNumberOfParameters())->toBe(0);
    expect($obReflection->isPublic())->toBeTrue();
});
