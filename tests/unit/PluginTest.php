<?php
declare(strict_types=1);

use Logingrupa\PostNordShippingShopaholic\Components\PostNordLocator;
use Logingrupa\PostNordShippingShopaholic\Plugin;
use System\Classes\PluginBase;

it('extends PluginBase', function (): void {
    expect(is_subclass_of(Plugin::class, PluginBase::class))
        ->toBeTrue();
});

it('requires Lovata.Toolbox and Lovata.OrdersShopaholic', function (): void {
    $obPlugin = new Plugin(app());
    $arRequired = $obPlugin->require;

    expect($arRequired)->toContain('Lovata.Toolbox');
    expect($arRequired)->toContain('Lovata.OrdersShopaholic');
});

it('registers PostNordLocator component', function (): void {
    $obPlugin = new Plugin(app());
    $arComponents = $obPlugin->registerComponents();

    expect($arComponents)->toHaveKey(PostNordLocator::class);
    expect($arComponents[PostNordLocator::class])->toBe('PostNordLocator');
});

it('has pluginDetails with Override attribute', function (): void {
    $obReflection = new ReflectionClass(Plugin::class);
    $obMethod = $obReflection->getMethod('pluginDetails');

    $arAttributes = $obMethod->getAttributes(\Override::class);
    expect($arAttributes)->toHaveCount(1);
});

it('has registerComponents with Override attribute', function (): void {
    $obReflection = new ReflectionClass(Plugin::class);
    $obMethod = $obReflection->getMethod('registerComponents');

    $arAttributes = $obMethod->getAttributes(\Override::class);
    expect($arAttributes)->toHaveCount(1);
});

it('returns plugin details with required keys', function (): void {
    $obPlugin = new Plugin(app());
    $arDetails = $obPlugin->pluginDetails();

    expect($arDetails)->toHaveKey('name');
    expect($arDetails)->toHaveKey('description');
    expect($arDetails)->toHaveKey('author');
    expect($arDetails)->toHaveKey('icon');
    expect($arDetails['author'])->toBe('Logingrupa');
});
