<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordNorwayClient;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;

uses(PostNordTestCase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('authenticates via OAuth2 and parses service points and parcel lockers sorted by distance', function (): void {
    Http::fake([
        'auth.postnord.no/*' => Http::response([
            'access_token' => 'test-jwt-token',
            'expires_in'   => 300,
            'token_type'   => 'bearer',
        ], 200),
        'atapi2.postnord.no/rest/api/products' => Http::response([
            'products' => [
                [
                    'productCode'      => 'H',
                    'displayName'      => 'PostNord Home',
                    'serviceLocations' => null,
                ],
                [
                    'productCode'      => 'Z',
                    'displayName'      => 'PostNord Service Point',
                    'serviceLocations' => [
                        [
                            'id'                => '50108356',
                            'name'              => 'AKERSGATA KOLONIAL AS JOKER AKERSGATA',
                            'address'           => 'AKERSGATA 5, 0158 OSLO',
                            'addressComponents' => [
                                'streetAddress' => 'AKERSGATA 5',
                                'postalCode'    => '0158',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'coordinates' => [
                                'wgs84X' => '10.738911',
                                'wgs84Y' => '59.910873',
                            ],
                            'distance' => 1293,
                        ],
                    ],
                ],
                [
                    'productCode'      => 'S',
                    'displayName'      => 'PostNord Parcel Locker',
                    'serviceLocations' => [
                        [
                            'id'                => '6412472',
                            'name'              => 'PAKKEAUTOMAT OSLO SENTRALSTASJON',
                            'address'           => 'JERNBANETORGET 1, 0154 OSLO',
                            'addressComponents' => [
                                'streetAddress' => 'JERNBANETORGET 1',
                                'postalCode'    => '0154',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'coordinates' => [
                                'wgs84X' => '10.753678',
                                'wgs84Y' => '59.912030',
                            ],
                            'distance' => 560,
                        ],
                        [
                            'id'                => '50108356',
                            'name'              => 'DUPLICATE SHOULD BE SKIPPED',
                            'addressComponents' => [
                                'streetAddress' => 'AKERSGATA 5',
                                'postalCode'    => '0158',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'distance' => 9999,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $obClient = new PostNordNorwayClient('client-id', 'client-secret', '50076769', '1528', true);
    $arResult = $obClient->findNearestByAddress('0150', 5);

    expect($arResult)->toHaveCount(2);
    expect($arResult[0]['service_point_id'])->toBe('6412472');
    expect($arResult[0]['name'])->toBe('PAKKEAUTOMAT OSLO SENTRALSTASJON');
    expect($arResult[0]['street_name'])->toBe('JERNBANETORGET 1');
    expect($arResult[0]['postal_code'])->toBe('0154');
    expect($arResult[0]['city'])->toBe('OSLO');
    expect($arResult[0]['country_code'])->toBe('NO');
    expect($arResult[0]['northing'])->toBe(59.912030);
    expect($arResult[0]['easting'])->toBe(10.753678);
    expect($arResult[0]['distance_in_meters'])->toBe(560);

    expect($arResult[1]['service_point_id'])->toBe('50108356');
    expect($arResult[1]['distance_in_meters'])->toBe(1293);

    Http::assertSent(static function (Request $obRequest): bool {
        if ($obRequest->url() !== 'https://atapi2.postnord.no/rest/api/products') {
            return false;
        }

        return $obRequest->hasHeader('Authorization', 'Bearer test-jwt-token')
            && data_get($obRequest->data(), 'settings.customerId') === '50076769'
            && data_get($obRequest->data(), 'consignment.consignor.postalCode') === '1528'
            && data_get($obRequest->data(), 'consignment.consignee.postalCode') === '0150';
    });
});

it('caches the OAuth2 access token across requests and refreshes on 401', function (): void {
    Http::fake([
        'auth.postnord.no/*' => Http::sequence()
            ->push(['access_token' => 'first-token', 'expires_in' => 300], 200)
            ->push(['access_token' => 'fresh-token', 'expires_in' => 300], 200),
        'api2.postnord.no/rest/api/products' => Http::sequence()
            ->push(['products' => []], 200)
            ->push('Unauthorized', 401)
            ->push([
                'products' => [
                    [
                        'serviceLocations' => [
                            [
                                'id'                => '111',
                                'name'              => 'Refreshed Point',
                                'addressComponents' => [
                                    'streetAddress' => 'Storgata 1',
                                    'postalCode'    => '0150',
                                    'postalArea'    => 'OSLO',
                                    'countryCode'   => 'NO',
                                ],
                                'distance' => 100,
                            ],
                        ],
                    ],
                ],
            ], 200),
    ]);

    $obClient = new PostNordNorwayClient('cid', 'csec', '50076769', '1528', false);
    expect($obClient->findNearestByAddress('0150'))->toBe([]);

    $arAfterRefresh = $obClient->findNearestByAddress('0150');
    expect($arAfterRefresh)->toHaveCount(1);
    expect($arAfterRefresh[0]['service_point_id'])->toBe('111');
});

it('validates credentials and reports errors in testConnection', function (): void {
    $obMissingSecret = new PostNordNorwayClient('cid', '', '50076769');
    expect($obMissingSecret->testConnection()['success'])->toBeFalse();
    expect($obMissingSecret->testConnection()['message'])->toContain('Client ID and Client Secret');

    $obMissingCustomer = new PostNordNorwayClient('cid', 'csec', '');
    expect($obMissingCustomer->testConnection()['success'])->toBeFalse();
    expect($obMissingCustomer->testConnection()['message'])->toContain('Customer ID');
});

it('reports OAuth2 failure and customer authorization errors in testConnection', function (): void {
    Http::fake([
        'auth.postnord.no/*' => Http::response(['error' => 'unauthorized_client'], 400),
    ]);

    $obClient = new PostNordNorwayClient('bad-id', 'bad-secret', '50076769', '1528', true);
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeFalse();
    expect($arResult['message'])->toContain('OAuth2 authentication failed');
    expect($arResult['message'])->toContain('400');
});

it('surfaces PostNord Norway 403 customerId error body in testConnection', function (): void {
    Http::fake([
        'auth.postnord.no/*' => Http::response(['access_token' => 'ok-token', 'expires_in' => 300], 200),
        'atapi2.postnord.no/rest/api/products' => Http::response(
            'Not authorized. company: 50076769 does not have access to company: 9999999',
            403
        ),
    ]);

    $obClient = new PostNordNorwayClient('cid', 'csec', '9999999', '1528', true);
    $arResult = $obClient->testConnection();

    expect($arResult['success'])->toBeFalse();
    expect($arResult['message'])->toContain('403');
    expect($arResult['message'])->toContain('50076769');
});

it('routes PostNordClient::fromConfig to Norway OAuth2 mode when configured', function (): void {
    Http::fake([
        'auth.postnord.no/*' => Http::response(['access_token' => 'norway-token', 'expires_in' => 300], 200),
        'atapi2.postnord.no/rest/api/products' => Http::response([
            'products' => [
                [
                    'serviceLocations' => [
                        [
                            'id'                => '42',
                            'name'              => 'Moss Pickup',
                            'addressComponents' => [
                                'streetAddress' => 'Gjeddeveien 18',
                                'postalCode'    => '1528',
                                'postalArea'    => 'MOSS',
                                'countryCode'   => 'NO',
                            ],
                            'distance' => 250,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $obClient = PostNordClient::fromConfig([
        'postnord_api_mode'           => PostNordClient::MODE_NORWAY,
        'postnord_sandbox'            => true,
        'postnord_client_id'          => '5c6dabf3',
        'postnord_client_secret'      => 'secret-value',
        'postnord_customer_id'        => '50076769',
        'postnord_sender_postal_code' => '1528',
    ]);

    $arTest = $obClient->testConnection();
    expect($arTest['success'])->toBeTrue();
    expect($arTest['message'])->toContain('sandbox');
    expect($arTest['message'])->toContain('1 pickup point(s)');

    $arPoints = $obClient->findNearestByAddress('1528');
    expect($arPoints)->toHaveCount(1);
    expect($arPoints[0]['name'])->toBe('Moss Pickup');
});

it('sends consignment.colli weight and products filter, parses live prices, and prefers cheaper duplicates', function (): void {
    Http::fake([
        'auth.postnord.no/*' => Http::response([
            'access_token' => 'pricing-token',
            'expires_in'   => 300,
        ], 200),
        'atapi2.postnord.no/rest/api/products' => Http::response([
            'products' => [
                [
                    'productCode' => 'Z',
                    'displayName' => 'PostNord Service Point',
                    'price'       => [
                        'priceBreakdown' => [
                            'netAmount' => ['value' => 119.0, 'currency' => 'NOK'],
                        ],
                    ],
                    'serviceLocations' => [
                        [
                            'id'                => '50108356',
                            'name'              => 'JOKER AKERSGATA',
                            'addressComponents' => [
                                'streetAddress' => 'AKERSGATA 5',
                                'postalCode'    => '0158',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'distance' => 300,
                        ],
                        [
                            'id'                => 'SHARED-1',
                            'name'              => 'SHARED POINT',
                            'addressComponents' => [
                                'streetAddress' => 'STORGATA 2',
                                'postalCode'    => '0150',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'distance' => 500,
                        ],
                    ],
                ],
                [
                    'productCode' => 'S',
                    'displayName' => 'PostNord Parcel Locker',
                    'price'       => [
                        'priceBreakdown' => [
                            'netAmount' => ['value' => 87.0, 'currency' => 'NOK'],
                        ],
                    ],
                    'serviceLocations' => [
                        [
                            'id'                => '6412472',
                            'name'              => 'PAKKEAUTOMAT OSLO S',
                            'addressComponents' => [
                                'streetAddress' => 'JERNBANETORGET 1',
                                'postalCode'    => '0154',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'distance' => 200,
                        ],
                        [
                            'id'                => 'SHARED-1',
                            'name'              => 'SHARED POINT LOCKER',
                            'addressComponents' => [
                                'streetAddress' => 'STORGATA 2',
                                'postalCode'    => '0150',
                                'postalArea'    => 'OSLO',
                                'countryCode'   => 'NO',
                            ],
                            'distance' => 500,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $obClient = new PostNordNorwayClient('cid', 'csec', '50076769', '1528', true);
    $arResult = $obClient->findNearestByAddress('0150', 10, 2000, ['mypackbox', 'mypack']);

    expect($arResult)->toHaveCount(3);
    expect($arResult[0]['service_point_id'])->toBe('6412472');
    expect($arResult[0]['product_code'])->toBe('S');
    expect($arResult[0]['price_net'])->toBe(87.0);
    expect($arResult[0]['price_currency'])->toBe('NOK');

    expect($arResult[1]['service_point_id'])->toBe('50108356');
    expect($arResult[1]['product_code'])->toBe('Z');
    expect($arResult[1]['price_net'])->toBe(119.0);

    // Duplicate SHARED-1 keeps cheaper product S (87 NOK) over Z (119 NOK)
    expect($arResult[2]['service_point_id'])->toBe('SHARED-1');
    expect($arResult[2]['product_code'])->toBe('S');
    expect($arResult[2]['price_net'])->toBe(87.0);

    Http::assertSent(static function (Request $obRequest): bool {
        if ($obRequest->url() !== 'https://atapi2.postnord.no/rest/api/products') {
            return false;
        }

        return data_get($obRequest->data(), 'consignment.colli.0.weight') === 2000
            && data_get($obRequest->data(), 'settings.products') === ['mypackbox', 'mypack'];
    });

    // testConnection includes live pricing note
    $arTest = $obClient->testConnection();
    expect($arTest['success'])->toBeTrue();
    expect($arTest['message'])->toContain('live pricing active (1 kg from 87 NOK net)');
});

it('parses selectable delivery options (S, B, Z, H) sorted cheapest-first and filters allowed codes', function (): void {
    Http::fake([
        'auth.postnord.no/*' => Http::response(['access_token' => 'cat-tok', 'expires_in' => 300], 200),
        'atapi2.postnord.no/rest/api/products' => Http::response([
            'products' => [
                [
                    'productCode'      => 'H',
                    'displayName'      => 'PostNord Home',
                    'texts'            => ['tip' => 'Hjemlevering 0-35 kg'],
                    'price'            => ['priceBreakdown' => ['netAmount' => ['value' => 246.0, 'currency' => 'NOK']]],
                    'expectedDelivery' => ['workingDays' => 1],
                    'serviceLocations' => null,
                ],
                [
                    'productCode'      => 'Q',
                    'displayName'      => 'PostNord Home Attended',
                    'price'            => ['priceBreakdown' => ['netAmount' => ['value' => 591.0, 'currency' => 'NOK']]],
                    'serviceLocations' => null,
                ],
                [
                    'productCode'      => 'B',
                    'displayName'      => 'PostNord Home Small',
                    'texts'            => ['tip' => 'Billigst'],
                    'price'            => ['priceBreakdown' => ['netAmount' => ['value' => 110.0, 'currency' => 'NOK']]],
                    'serviceLocations' => null,
                ],
                [
                    'productCode'      => 'S',
                    'displayName'      => 'PostNord Parcel Locker',
                    'texts'            => ['tip' => 'Hent i pakkeautomat'],
                    'price'            => ['priceBreakdown' => ['netAmount' => ['value' => 83.0, 'currency' => 'NOK']]],
                    'expectedDelivery' => ['workingDays' => 1],
                    'serviceLocations' => [
                        [
                            'id'                => '6412472',
                            'name'              => 'PAKKEAUTOMAT OSLO S',
                            'addressComponents' => ['streetAddress' => 'JERNBANETORGET 1', 'postalCode' => '0154', 'postalArea' => 'OSLO', 'countryCode' => 'NO'],
                            'distance'          => 200,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $obClient = new PostNordNorwayClient('cid', 'csec', '50076769', '1528', true);
    $arCatalog = $obClient->findDeliveryCatalog('0150', 10, 500, [], ['S', 'Z', 'B', 'H']);

    // Q is excluded; S (83) -> B (110) -> H (246) sorted cheapest-first
    expect(array_column($arCatalog['products'], 'product_code'))->toBe(['S', 'B', 'H']);
    expect($arCatalog['products'][0]['needs_pickup_point'])->toBeTrue();
    expect($arCatalog['products'][1]['needs_pickup_point'])->toBeFalse();
    expect($arCatalog['products'][2]['needs_pickup_point'])->toBeFalse();
    expect($arCatalog['products'][2]['working_days'])->toBe(1);
    expect($arCatalog['points'])->toHaveCount(1);
});
