<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordPriceCalculator;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordSessionQuoteStore;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;
use Lovata\Shopaholic\Models\Currency;

uses(PostNordTestCase::class);

beforeEach(function (): void {
    PostNordSessionQuoteStore::clearSessionQuotes();

    if (!Schema::hasTable('lovata_shopaholic_currency')) {
        Schema::create('lovata_shopaholic_currency', function (Blueprint $obTable): void {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->boolean('is_default')->default(false);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('symbol')->nullable();
            $obTable->decimal('rate', 15, 4)->default(1);
            $obTable->integer('sort_order')->default(0);
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }
});

it('enables dynamic pricing only for Norway mode when explicitly opted in', function (): void {
    expect(PostNordPriceCalculator::isDynamicPricingEnabled([]))->toBeFalse();
    expect(PostNordPriceCalculator::isDynamicPricingEnabled([
        'postnord_api_mode'        => PostNordClient::MODE_NORWAY,
        'postnord_dynamic_pricing' => false,
    ]))->toBeFalse();
    expect(PostNordPriceCalculator::isDynamicPricingEnabled([
        'postnord_api_mode'        => PostNordClient::MODE_NORDIC,
        'postnord_dynamic_pricing' => true,
    ]))->toBeFalse();
    expect(PostNordPriceCalculator::isDynamicPricingEnabled([
        'postnord_api_mode'        => PostNordClient::MODE_NORWAY,
        'postnord_dynamic_pricing' => true,
    ]))->toBeTrue();
    expect(PostNordPriceCalculator::isDynamicPricingEnabled([
        'postnord_client_id'       => '5c6dabf3',
        'postnord_dynamic_pricing' => '1',
    ]))->toBeTrue();
});

it('resolves product filter options cleanly', function (): void {
    // Default enabled codes include S, Z, B, H -> empty API filter so PostNord returns all options
    expect(PostNordPriceCalculator::resolveEnabledProductCodes([]))
        ->toBe(['S', 'Z', 'B', 'H']);
    expect(PostNordPriceCalculator::resolveProductFilterList([]))
        ->toBe([]);

    // Pickup-only selections pass explicit settings.products filter
    expect(PostNordPriceCalculator::resolveProductFilterList(['postnord_product_filter' => 'mypackbox']))
        ->toBe(['mypackbox']);
    expect(PostNordPriceCalculator::resolveProductFilterList(['postnord_product_filter' => 'mypack']))
        ->toBe(['mypack']);
    expect(PostNordPriceCalculator::resolveProductFilterList(['postnord_enabled_products' => ['S', 'Z']]))
        ->toBe(['mypackbox', 'mypack']);
    expect(PostNordPriceCalculator::resolveEnabledProductCodes(['postnord_enabled_products' => ['S', 'H', 'INVALID']]))
        ->toBe(['S', 'H']);
});

it('calculates total cart weight in grams with fallback for zero-weight items and 100g floor', function (): void {
    // Mixed cart: item with explicit line weight (120g) + 2x item without weight (fallback 500g each)
    $arPositions = [
        (object) ['quantity' => 2, 'weight' => 120.0],
        (object) ['quantity' => 2, 'weight' => 0.0],
        (object) ['quantity' => 0, 'weight' => 999.0],
    ];
    expect(PostNordPriceCalculator::calculateCartWeightGrams($arPositions, 500))->toBe(1120);

    // Very light cart enforces 100g minimum floor
    expect(PostNordPriceCalculator::calculateCartWeightGrams([
        ['quantity' => 1, 'weight' => 35.0],
    ], 500))->toBe(100);

    // Empty cart enforces 100g minimum floor
    expect(PostNordPriceCalculator::calculateCartWeightGrams([], 500))->toBe(100);
});

it('applies VAT, markup, and rounding strategies accurately', function (): void {
    // 83 NOK net * 1.25 = 103.75
    expect(PostNordPriceCalculator::calculateCustomerPrice(83.0, [
        'postnord_vat_percent'    => 25,
        'postnord_price_markup'   => 0,
        'postnord_price_rounding' => PostNordPriceCalculator::ROUND_CEIL,
    ]))->toBe(104.0);

    expect(PostNordPriceCalculator::calculateCustomerPrice(83.0, [
        'postnord_vat_percent'    => 25,
        'postnord_price_markup'   => 0,
        'postnord_price_rounding' => PostNordPriceCalculator::ROUND_CEIL_5,
    ]))->toBe(105.0);

    expect(PostNordPriceCalculator::calculateCustomerPrice(83.0, [
        'postnord_vat_percent'    => 25,
        'postnord_price_markup'   => 0,
        'postnord_price_rounding' => PostNordPriceCalculator::ROUND_CEIL_10,
    ]))->toBe(110.0);

    expect(PostNordPriceCalculator::calculateCustomerPrice(83.0, [
        'postnord_vat_percent'    => 25,
        'postnord_price_markup'   => 10,
        'postnord_price_rounding' => PostNordPriceCalculator::ROUND_NONE,
    ]))->toBe(113.75);

    expect(PostNordPriceCalculator::calculateCustomerPrice(0.0, []))->toBe(0.0);
});

it('converts NOK amounts to default shop currency matching Lovata CurrencyHelper inverse and leaves unchanged when NOK', function (): void {
    Currency::query()->delete();

    // In Lovata.Shopaholic CurrencyHelper::convert($price, $to) = (default.rate * $price) / $to.rate
    // So 1 EUR = 12 NOK means EUR.rate = 1.0 and NOK.rate = 1/12 (0.083333)
    Currency::create([
        'active'     => true,
        'is_default' => true,
        'name'       => 'Euro',
        'code'       => 'EUR',
        'symbol'     => '€',
        'rate'       => 1.0,
    ]);
    Currency::create([
        'active'     => true,
        'is_default' => false,
        'name'       => 'Norwegian Krone',
        'code'       => 'NOK',
        'symbol'     => 'kr',
        'rate'       => 0.083333,
    ]);

    expect(PostNordPriceCalculator::convertToDefaultCurrency(120.0, 'NOK'))->toBe(10.0);
    expect(PostNordPriceCalculator::convertToDefaultCurrency(104.0, 'NOK'))->toBe(8.67);

    // Round-trip via Lovata CurrencyHelper::convert() must recover original NOK amount
    \Lovata\Shopaholic\Classes\Helper\CurrencyHelper::forgetInstance();
    $fEur = PostNordPriceCalculator::convertToDefaultCurrency(120.0, 'NOK');
    expect(round(\Lovata\Shopaholic\Classes\Helper\CurrencyHelper::instance()->convert($fEur, 'NOK'), 0))->toBe(120.0);

    // Switch default currency to NOK (nailscosmetics.no setup)
    Currency::where('code', 'EUR')->update(['is_default' => false, 'rate' => 12.0]);
    Currency::where('code', 'NOK')->update(['is_default' => true, 'rate' => 1.0]);

    expect(PostNordPriceCalculator::convertToDefaultCurrency(104.0, 'NOK'))->toBe(104.0);
});

it('stores and retrieves server-side session quotes, enforces postal_code match, and never trusts client input', function (): void {
    PostNordSessionQuoteStore::storePointQuotes(30, '0150', 500, [
        '6412472' => [
            'price_default'         => 104.0,
            'price_net'             => 83.0,
            'currency'              => 'NOK',
            'product_code'          => 'S',
            'product_name'          => 'PostNord Parcel Locker',
            'service_point_name'    => 'PAKKEAUTOMAT OSLO S',
            'service_point_address' => 'JERNBANETORGET 1, 0154 OSLO',
        ],
        'product:H' => [
            'price_default' => 278.0,
            'price_net'     => 222.0,
            'currency'      => 'NOK',
            'product_code'  => 'H',
            'product_name'  => 'PostNord Home',
        ],
    ]);

    // Unknown point ID returns null
    expect(PostNordSessionQuoteStore::selectPointQuote(30, '999999', '0150'))->toBeNull();
    // Wrong postal code rejects even matching product:H key
    expect(PostNordSessionQuoteStore::selectPointQuote(30, 'product:H', '9008'))->toBeNull();
    expect(PostNordSessionQuoteStore::getActiveSelectedQuote(30))->toBeNull();

    // Selecting known locker with matching postal code activates authoritative server quote
    $arSelected = PostNordSessionQuoteStore::selectPointQuote(30, '6412472', '0150');
    expect($arSelected)->not->toBeNull();
    expect($arSelected['price_default'])->toBe(104.0);
    expect($arSelected['product_code'])->toBe('S');
    expect($arSelected['product_name'])->toBe('PostNord Parcel Locker');
    expect($arSelected['service_point_name'])->toBe('PAKKEAUTOMAT OSLO S');
    expect($arSelected['weight_grams'])->toBe(500);

    // Active quote only matches shipping type 30
    expect(PostNordSessionQuoteStore::getActiveSelectedQuote(30)['price_default'] ?? null)->toBe(104.0);
    expect(PostNordSessionQuoteStore::getActiveSelectedQuote(99))->toBeNull();

    // clearSelectedQuote clears only active selection
    PostNordSessionQuoteStore::clearSelectedQuote(30);
    expect(PostNordSessionQuoteStore::getActiveSelectedQuote(30))->toBeNull();

    // Clearing removes both quote maps and active selection
    PostNordSessionQuoteStore::clearSessionQuotes();
    expect(Session::get(PostNordSessionQuoteStore::SESSION_QUOTES_KEY))->toBeNull();
});
