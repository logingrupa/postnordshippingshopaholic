<?php
declare(strict_types=1);

use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType\ExtendShippingTypeFieldsHandler;

it('has subscribe method', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('subscribe');

    expect($obMethod->isPublic())->toBeTrue();
});

it('has extendFields method', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('extendFields');

    expect($obMethod->isProtected())->toBeTrue();
});

it('returns correct PostNord field definitions from getPostNordFields', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('getPostNordFields');
    $obMethod->setAccessible(true);

    $obHandler = new ExtendShippingTypeFieldsHandler();
    /** @var array<string, array<string, mixed>> $arFields */
    $arFields = $obMethod->invoke($obHandler);

    expect($arFields)->toHaveKey('postnord_info');
    expect($arFields)->toHaveKey('property[postnord_api_mode]');
    expect($arFields)->toHaveKey('property[postnord_sandbox]');
    expect($arFields)->toHaveKey('property[postnord_client_id]');
    expect($arFields)->toHaveKey('property[postnord_client_secret]');
    expect($arFields)->toHaveKey('property[postnord_customer_id]');
    expect($arFields)->toHaveKey('property[postnord_sender_postal_code]');
    expect($arFields)->toHaveKey('property[postnord_api_key]');
    expect($arFields)->toHaveKey('property[postnord_country_code]');
    expect($arFields)->toHaveKey('property[postnord_max_results]');
    expect($arFields)->toHaveKey('postnord_test_connection');
});

it('includes test connection partial field', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('getPostNordFields');
    $obMethod->setAccessible(true);

    $obHandler = new ExtendShippingTypeFieldsHandler();
    /** @var array<string, array<string, mixed>> $arFields */
    $arFields = $obMethod->invoke($obHandler);

    $arTestField = $arFields['postnord_test_connection'];
    expect($arTestField['type'])->toBe('partial');
    expect($arTestField['path'])->toContain('_test_connection');
    expect($arTestField['context'])->toBe(['create', 'update']);
});

it('applies trigger conditions to all fields referencing PostNordShippingProcessor', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('getPostNordFields');
    $obMethod->setAccessible(true);

    $obHandler = new ExtendShippingTypeFieldsHandler();
    /** @var array<string, array<string, mixed>> $arFields */
    $arFields = $obMethod->invoke($obHandler);
    $sExpectedCondition = 'value[' . PostNordShippingProcessor::class . ']';

    foreach ($arFields as $arFieldConfig) {
        expect($arFieldConfig)->toHaveKey('trigger');
        expect($arFieldConfig['trigger']['action'])->toBe('show');
        expect($arFieldConfig['trigger']['field'])->toBe('api_class');
        expect($arFieldConfig['trigger']['condition'])->toBe($sExpectedCondition);
    }
});

it('sets correct tab on all fields', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('getPostNordFields');
    $obMethod->setAccessible(true);

    $obHandler = new ExtendShippingTypeFieldsHandler();
    /** @var array<string, array<string, mixed>> $arFields */
    $arFields = $obMethod->invoke($obHandler);

    $sExpectedTab = 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup';

    foreach ($arFields as $sFieldName => $arFieldConfig) {
        expect($arFieldConfig['tab'])->toBe($sExpectedTab, "Field '{$sFieldName}' has wrong tab");
    }
});

it('has extendShippingTypesController method', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('extendShippingTypesController');

    expect($obMethod->isProtected())->toBeTrue();
});

it('hides Nordic fields in Norway mode and Norway fields in Nordic mode', function (): void {
    $obReflection = new ReflectionClass(ExtendShippingTypeFieldsHandler::class);
    $obMethod = $obReflection->getMethod('filterModeFields');
    $obMethod->setAccessible(true);

    $fnMakeFields = static function (string $sMode): \October\Rain\Element\ElementHolder {
        $arNames = [
            'property[postnord_api_mode]',
            'property[postnord_client_id]',
            'property[postnord_client_secret]',
            'property[postnord_customer_id]',
            'property[postnord_sender_postal_code]',
            'property[postnord_api_key]',
            'property[postnord_country_code]',
        ];
        $arMap = [];
        foreach ($arNames as $sName) {
            $obField = new \Backend\Classes\FormField($sName, $sName);
            $arMap[$sName] = $obField;
        }
        $arMap['property[postnord_api_mode]']->value = $sMode;

        return new \October\Rain\Element\ElementHolder($arMap);
    };

    $obHandler = new ExtendShippingTypeFieldsHandler();

    $obNorwayFields = $fnMakeFields('norway');
    $obMethod->invoke($obHandler, $obNorwayFields);
    expect($obNorwayFields->get('property[postnord_api_key]')->hidden)->toBeTrue();
    expect($obNorwayFields->get('property[postnord_country_code]')->hidden)->toBeTrue();
    expect($obNorwayFields->get('property[postnord_client_id]')->hidden)->toBeFalse();
    expect($obNorwayFields->get('property[postnord_client_secret]')->hidden)->toBeFalse();

    $obNordicFields = $fnMakeFields('nordic');
    $obMethod->invoke($obHandler, $obNordicFields);
    expect($obNordicFields->get('property[postnord_api_key]')->hidden)->toBeFalse();
    expect($obNordicFields->get('property[postnord_country_code]')->hidden)->toBeFalse();
    expect($obNordicFields->get('property[postnord_client_id]')->hidden)->toBeTrue();
    expect($obNordicFields->get('property[postnord_client_secret]')->hidden)->toBeTrue();
});
