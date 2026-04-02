<?php
declare(strict_types=1);

use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType\ExtendShippingTypeModel;

it('has subscribe method', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeModel::class);
    $obMethod = $obReflection->getMethod('subscribe');

    expect($obMethod->isPublic())->toBeTrue();
});

it('returns PostNordShippingProcessor in handleGetApiClassList', function (): void {
    $arResult = ExtendShippingTypeModel::handleGetApiClassList();

    expect($arResult)->toBeArray();
    expect($arResult)->toHaveKey(PostNordShippingProcessor::class);
    expect($arResult[PostNordShippingProcessor::class])->toBe('PostNord Pickup');
});

it('returns exactly one entry from handleGetApiClassList', function (): void {
    $arResult = ExtendShippingTypeModel::handleGetApiClassList();

    expect($arResult)->toHaveCount(1);
});

it('handleGetApiClassList is a static method', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeModel::class);
    $obMethod = $obReflection->getMethod('handleGetApiClassList');

    expect($obMethod->isStatic())->toBeTrue();
    expect($obMethod->isPublic())->toBeTrue();
});
