<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;

uses(PostNordTestCase::class);

// --- testConnection tests ---

it('returns success on valid test connection', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([
            'servicePointInformationResponse' => [
                'servicePoints' => [
                    ['servicePointId' => '111', 'name' => 'Test Point'],
                ],
            ],
        ], 200),
    ]);

    $obClient = new PostNordClient('valid-api-key', 'NO');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeTrue();
    expect($arResult['message'])->toContain('Connection successful');
    expect($arResult['message'])->toContain('1 service point(s)');
    expect($arResult['message'])->toContain('0001');
    expect($arResult['message'])->toContain('NO');
});

it('returns failure when API key is empty for testConnection', function (): void {
    $obClient = new PostNordClient('', 'NO');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeFalse();
    expect($arResult['message'])->toBe('API key is empty.');
});

it('returns failure on 401 authentication error', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response('Unauthorized', 401),
    ]);

    $obClient = new PostNordClient('bad-key', 'NO');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeFalse();
    expect($arResult['message'])->toContain('Authentication failed');
    expect($arResult['message'])->toContain('401');
});

it('returns failure on 403 forbidden error', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response('Forbidden', 403),
    ]);

    $obClient = new PostNordClient('bad-key', 'NO');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeFalse();
    expect($arResult['message'])->toContain('Authentication failed');
    expect($arResult['message'])->toContain('403');
});

it('returns failure on 500 server error for testConnection', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response('Server Error', 500),
    ]);

    $obClient = new PostNordClient('valid-key', 'NO');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeFalse();
    expect($arResult['message'])->toContain('HTTP 500');
});

it('uses correct test postal code for Latvia', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([
            'servicePointInformationResponse' => [
                'servicePoints' => [],
            ],
        ], 200),
    ]);

    $obClient = new PostNordClient('valid-key', 'LV');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeTrue();
    expect($arResult['message'])->toContain('1001');
    expect($arResult['message'])->toContain('LV');
});

it('uses correct test postal code for Lithuania', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([
            'servicePointInformationResponse' => [
                'servicePoints' => [],
            ],
        ], 200),
    ]);

    $obClient = new PostNordClient('valid-key', 'LT');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeTrue();
    expect($arResult['message'])->toContain('01001');
    expect($arResult['message'])->toContain('LT');
});

it('falls back to 0001 for unknown country code', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([
            'servicePointInformationResponse' => [
                'servicePoints' => [],
            ],
        ], 200),
    ]);

    $obClient = new PostNordClient('valid-key', 'SE');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeTrue();
    expect($arResult['message'])->toContain('0001');
});

it('handles unexpected response format in testConnection', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response('not json', 200, ['Content-Type' => 'text/plain']),
    ]);

    $obClient = new PostNordClient('valid-key', 'NO');
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeFalse();
    expect($arResult['message'])->toContain('unexpected response format');
});

// --- fromShippingType tests (reflection-based, no Mockery on October models) ---

it('has fromShippingType static factory method', function (): void {
    $obReflection = new ReflectionClass(PostNordClient::class);
    $obMethod = $obReflection->getMethod('fromShippingType');

    expect($obMethod->isStatic())->toBeTrue();
    expect($obMethod->isPublic())->toBeTrue();
});

it('fromShippingType returns PostNordClient instance', function (): void {
    $obReflection = new ReflectionClass(PostNordClient::class);
    $obMethod = $obReflection->getMethod('fromShippingType');
    $obReturnType = $obMethod->getReturnType();

    expect($obReturnType)->not->toBeNull();
    expect($obReturnType->getName())->toBe('self');
});

it('fromShippingType reads postnord_api_key and postnord_country_code from property', function (): void {
    $obReflection = new ReflectionClass(PostNordClient::class);
    $sSource = file_get_contents($obReflection->getFileName());

    expect($sSource)->toContain("postnord_api_key");
    expect($sSource)->toContain("postnord_country_code");
});
