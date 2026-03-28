<?php

declare(strict_types=1);

use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;

it('has correct table name', function (): void {
    $obServicePoint = new ServicePoint();

    expect($obServicePoint->table)->toBe('logingrupa_postnord_service_points');
});

it('has correct fillable fields', function (): void {
    $obServicePoint = new ServicePoint();
    $arExpectedFillable = [
        'service_point_id',
        'name',
        'street_name',
        'street_number',
        'postal_code',
        'city',
        'country_code',
        'northing',
        'easting',
        'distance_in_meters',
    ];

    expect($obServicePoint->fillable)->toBe($arExpectedFillable);
});

it('has required validation rules', function (): void {
    $obServicePoint = new ServicePoint();

    expect($obServicePoint->rules)->toHaveKey('service_point_id');
    expect($obServicePoint->rules)->toHaveKey('name');
    expect($obServicePoint->rules)->toHaveKey('postal_code');
    expect($obServicePoint->rules)->toHaveKey('city');
    expect($obServicePoint->rules)->toHaveKey('country_code');

    expect($obServicePoint->rules['country_code'])->toContain('size:2');
});

it('accepts valid attribute assignment', function (): void {
    $obServicePoint = new ServicePoint([
        'service_point_id' => '123456',
        'name'             => 'Coop Extra Moss',
        'street_name'      => 'Gjeddeveien',
        'street_number'    => '18',
        'postal_code'      => '1528',
        'city'             => 'MOSS',
        'country_code'     => 'NO',
        'northing'         => 59.4340,
        'easting'          => 10.6590,
    ]);

    expect($obServicePoint->service_point_id)->toBe('123456');
    expect($obServicePoint->name)->toBe('Coop Extra Moss');
    expect($obServicePoint->street_name)->toBe('Gjeddeveien');
    expect($obServicePoint->postal_code)->toBe('1528');
    expect($obServicePoint->city)->toBe('MOSS');
    expect($obServicePoint->country_code)->toBe('NO');
});
