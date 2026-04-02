<?php
declare(strict_types=1);

use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Interfaces\ShippingPriceProcessorInterface;

it('implements ShippingPriceProcessorInterface', function (): void {
    $arInterfaces = class_implements(PostNordShippingProcessor::class);
    expect($arInterfaces)->toBeArray();
    expect($arInterfaces)->toHaveKey(ShippingPriceProcessorInterface::class);
});

it('returns fields array with expected keys from getFields', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    expect($arFields)->toBeArray();
    expect($arFields)->toHaveKey('postnord_info');
    expect($arFields)->toHaveKey('property[postnord_api_key]');
    expect($arFields)->toHaveKey('property[postnord_country_code]');
    expect($arFields)->toHaveKey('property[postnord_max_results]');
});

it('returns section type for postnord_info field', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    expect($arFields['postnord_info']['type'])->toBe('section');
    expect($arFields['postnord_info']['span'])->toBe('full');
});

it('returns text type for api_key field with required flag', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    $arApiKeyField = $arFields['property[postnord_api_key]'];
    expect($arApiKeyField['type'])->toBe('text');
    expect($arApiKeyField['required'])->toBeTrue();
});

it('returns dropdown type for country_code field with correct options', function (): void {
    $arFields = PostNordShippingProcessor::getFields();

    $arCountryField = $arFields['property[postnord_country_code]'];
    expect($arCountryField['type'])->toBe('dropdown');
    expect($arCountryField['default'])->toBe('NO');
    expect($arCountryField['options'])->toBe([
        'NO' => 'Norway',
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
