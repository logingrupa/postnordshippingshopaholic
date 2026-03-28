<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;

uses(PostNordTestCase::class);

it('parses service points from API response', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([
            'servicePointInformationResponse' => [
                'servicePoints' => [
                    [
                        'servicePointId' => '123456',
                        'name' => 'Coop Extra Moss',
                        'visitingAddress' => [
                            'streetName' => 'Gjeddeveien',
                            'streetNumber' => '18',
                            'postalCode' => '1528',
                            'city' => 'MOSS',
                            'countryCode' => 'NO',
                        ],
                        'coordinates' => [
                            ['northing' => 59.4340, 'easting' => 10.6590, 'srId' => 'EPSG:4326'],
                        ],
                    ],
                    [
                        'servicePointId' => '789012',
                        'name' => 'Rema 1000 Kambo',
                        'visitingAddress' => [
                            'streetName' => 'Kamboveien',
                            'streetNumber' => '5',
                            'postalCode' => '1530',
                            'city' => 'MOSS',
                            'countryCode' => 'NO',
                        ],
                        'coordinates' => [
                            ['northing' => 59.4400, 'easting' => 10.6700, 'srId' => 'EPSG:4326'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $obClient = new PostNordClient('test-api-key', 'NO');
    $arResult = $obClient->findNearestByAddress('1528');

    expect($arResult)->toHaveCount(2);
    expect($arResult[0]['service_point_id'])->toBe('123456');
    expect($arResult[0]['name'])->toBe('Coop Extra Moss');
    expect($arResult[0]['street_name'])->toBe('Gjeddeveien');
    expect($arResult[0]['street_number'])->toBe('18');
    expect($arResult[0]['postal_code'])->toBe('1528');
    expect($arResult[0]['city'])->toBe('MOSS');
    expect($arResult[0]['country_code'])->toBe('NO');
    expect($arResult[0]['northing'])->toBe(59.4340);
    expect($arResult[0]['easting'])->toBe(10.6590);

    expect($arResult[1]['service_point_id'])->toBe('789012');
    expect($arResult[1]['name'])->toBe('Rema 1000 Kambo');
});

it('returns empty array on API error', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response('Server Error', 500),
    ]);

    $obClient = new PostNordClient('test-api-key', 'NO');
    $arResult = $obClient->findNearestByAddress('1528');

    expect($arResult)->toBeArray()->toBeEmpty();
});

it('returns empty array on empty postal code', function (): void {
    $obClient = new PostNordClient('test-api-key', 'NO');
    $arResult = $obClient->findNearestByAddress('');

    expect($arResult)->toBeArray()->toBeEmpty();
});

it('returns empty array when API key is empty', function (): void {
    $obClient = new PostNordClient('', 'NO');
    $arResult = $obClient->findNearestByAddress('1528');

    expect($arResult)->toBeArray()->toBeEmpty();
});

it('handles missing coordinates gracefully', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([
            'servicePointInformationResponse' => [
                'servicePoints' => [
                    [
                        'servicePointId' => '999999',
                        'name' => 'No Coords Point',
                        'visitingAddress' => [
                            'streetName' => 'TestStreet',
                            'streetNumber' => '1',
                            'postalCode' => '0001',
                            'city' => 'OSLO',
                            'countryCode' => 'NO',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $obClient = new PostNordClient('test-api-key', 'NO');
    $arResult = $obClient->findNearestByAddress('0001');

    expect($arResult)->toHaveCount(1);
    expect($arResult[0]['northing'])->toBeNull();
    expect($arResult[0]['easting'])->toBeNull();
});

it('handles empty service points array', function (): void {
    Http::fake([
        'api2.postnord.com/*' => Http::response([
            'servicePointInformationResponse' => [
                'servicePoints' => [],
            ],
        ], 200),
    ]);

    $obClient = new PostNordClient('test-api-key', 'NO');
    $arResult = $obClient->findNearestByAddress('9999');

    expect($arResult)->toBeArray()->toBeEmpty();
});
