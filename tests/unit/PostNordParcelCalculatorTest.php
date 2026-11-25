<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordMeasureHelper;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordNorwayPayloadBuilder;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordParcelCalculator;
use Logingrupa\PostNordShippingShopaholic\Tests\PostNordTestCase;
use Lovata\Shopaholic\Models\Currency;

uses(PostNordTestCase::class);

beforeEach(function (): void {
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

it('uses bubble wrap envelope in native mm/gr units and converts outer parcel to PostNord cm, switching to default box when too big or heavy', function (): void {
    $arProperty = [
        'postnord_default_weight_grams' => 500,
        'postnord_packaging_weight'     => 80,
        'postnord_box_length'           => 250,
        'postnord_box_width'            => 180,
        'postnord_box_height'           => 120,
        'postnord_envelope_enabled'     => true,
        'postnord_envelope_max_weight'  => 1000,
        'postnord_envelope_length'      => 180,
        'postnord_envelope_width'       => 120,
        'postnord_envelope_height'      => 40,
    ];

    // 2x 15ml nail polish bottle (55g each = 110g line, 28x28x84 mm) -> fits 180x120x40 mm bubble envelope -> 18x12x4 cm for PostNord!
    $arSmallCart = [
        (object) [
            'quantity' => 2,
            'weight'   => 110.0,
            'offer'    => (object) ['length' => 28, 'width' => 28, 'height' => 84],
        ],
    ];

    $arSmallParcel = PostNordParcelCalculator::calculateParcelForCart($arSmallCart, $arProperty);
    expect($arSmallParcel['packaging'])->toBe('envelope');
    expect($arSmallParcel['weight'])->toBe(190); // 110g items + 80g tare
    expect($arSmallParcel['length'])->toBe(18);
    expect($arSmallParcel['width'])->toBe(12);
    expect($arSmallParcel['height'])->toBe(4);

    // Oversized item (UV nail lamp: 220x160x90 mm) -> switches to default box (250x180x120 mm -> 25x18x12 cm)
    $arLampCart = [
        (object) [
            'quantity' => 1,
            'weight'   => 650.0,
            'offer'    => (object) ['length' => 220, 'width' => 160, 'height' => 90],
        ],
    ];

    $arLampParcel = PostNordParcelCalculator::calculateParcelForCart($arLampCart, $arProperty);
    expect($arLampParcel['packaging'])->toBe('box');
    expect($arLampParcel['weight'])->toBe(730);
    expect($arLampParcel['length'])->toBe(25);
    expect($arLampParcel['width'])->toBe(18);
    expect($arLampParcel['height'])->toBe(12);

    // Heavy cart (1200g > 1000g envelope limit) -> switches to default box even for small bottles
    $arHeavyCart = [
        (object) [
            'quantity' => 10,
            'weight'   => 1200.0,
            'offer'    => (object) ['length' => 28, 'width' => 28, 'height' => 84],
        ],
    ];

    $arHeavyParcel = PostNordParcelCalculator::calculateParcelForCart($arHeavyCart, $arProperty);
    expect($arHeavyParcel['packaging'])->toBe('box');
    expect($arHeavyParcel['weight'])->toBe(1280);
});

it('expands default box mm dimensions when an item is larger than the configured box and omits dims when unconfigured', function (): void {
    $arNoBox = PostNordParcelCalculator::calculateParcelForCart([
        ['quantity' => 1, 'weight' => 300.0],
    ], []);
    expect($arNoBox['packaging'])->toBe('none');
    expect($arNoBox['length'])->toBeNull();
    expect($arNoBox['weight'])->toBe(300);

    // Item longer than configured 200x150x100 mm box -> expands length to 350 mm -> 35 cm
    $arExpanded = PostNordParcelCalculator::calculateParcelForCart([
        [
            'quantity' => 1,
            'weight'   => 400.0,
            'offer'    => ['length' => 350, 'width' => 100, 'height' => 80],
        ],
    ], [
        'postnord_box_length' => 200,
        'postnord_box_width'  => 150,
        'postnord_box_height' => 100,
    ]);

    expect($arExpanded['packaging'])->toBe('box');
    expect($arExpanded['length'])->toBe(35);
    expect($arExpanded['width'])->toBe(15);
    expect($arExpanded['height'])->toBe(10);
});

it('formats backend labels with active Shopaholic measure units and emits cm colli in B2C Products API payload', function (): void {
    Currency::query()->delete();
    Currency::create([
        'active' => true, 'is_default' => true, 'name' => 'EUR', 'code' => 'EUR', 'symbol' => '€', 'rate' => 1.0,
    ]);
    // Lovata CurrencyHelper::convert($eur, 'NOK') = (1.0 * $eur) / 0.083333 => 50 EUR = 600 NOK
    Currency::create([
        'active' => true, 'is_default' => false, 'name' => 'NOK', 'code' => 'NOK', 'symbol' => 'kr', 'rate' => 0.083333,
    ]);

    expect(PostNordMeasureHelper::formatDimensionLabel('logingrupa.postnordshippingshopaholic::lang.field.box_length'))
        ->toEndWith('(mm)');
    expect(PostNordMeasureHelper::formatWeightLabel('logingrupa.postnordshippingshopaholic::lang.field.packaging_weight'))
        ->toEndWith('(gr)');

    $arParcel = PostNordParcelCalculator::calculateParcelForCart(
        [['quantity' => 1, 'weight' => 250.0]],
        [
            'postnord_packaging_weight'        => 50,
            'postnord_box_length'              => 200,
            'postnord_box_width'               => 150,
            'postnord_box_height'              => 100,
            'postnord_parcel_letter_max_price' => 1000,
        ],
        50.0 // 50 EUR / 0.083333 = 600 NOK
    );

    $arPayload = PostNordNorwayPayloadBuilder::buildProductsPayload('50076769', '1528', '0150', 10, $arParcel['weight'], [], $arParcel);

    expect(data_get($arPayload, 'consignment.colli.0'))->toBe([
        'weight' => 300,
        'length' => 20,
        'width'  => 15,
        'height' => 10,
    ]);
    expect(data_get($arPayload, 'settings.parcelLetterMaxPrice'))->toBe(['value' => 1000.0, 'currency' => 'NOK']);
    expect(round((float) data_get($arPayload, 'consignment.shoppingCartTotalPrice.value'), 0))->toBe(600.0);
});

it('reads computed weight, offer dimensions, and price from real Lovata MainItem subclasses without MainItem::__isset returning null', function (): void {
    // Simulate Lovata MainItem::__isset() which only checks $arModelData and returns false for computed attributes
    $obOfferStub = new class() extends \Lovata\Toolbox\Classes\Item\ElementItem {
        public function __construct()
        {
            $this->iElementID = 1;
            $this->arModelData = ['id' => 1];
        }

        protected function getElementData(): void
        {
        }

        protected function getLengthAttribute(): float
        {
            return 220.0;
        }

        protected function getWidthAttribute(): float
        {
            return 160.0;
        }

        protected function getHeightAttribute(): float
        {
            return 90.0;
        }

        protected function getPriceValueAttribute(): float
        {
            return 25.0;
        }
    };

    $obPositionStub = new class($obOfferStub) extends \Lovata\Toolbox\Classes\Item\ElementItem {
        public function __construct(private readonly object $obOfferItem)
        {
            $this->iElementID = 10;
            $this->arModelData = ['id' => 10, 'quantity' => 2];
        }

        protected function getElementData(): void
        {
        }

        protected function getWeightAttribute(): float
        {
            return 130.0;
        }

        protected function getOfferAttribute(): object
        {
            return $this->obOfferItem;
        }
    };

    // Confirm PHP `??` returns null on MainItem computed attributes (the exact Lovata gotcha)
    expect($obPositionStub->weight ?? null)->toBeNull();
    expect($obPositionStub->offer ?? null)->toBeNull();

    // PostNordParcelCalculator MUST still read weight=130g (NOT 2*500g), offer dims 220x160x90mm (switching out of 180x120x40mm envelope), and subtotal=50
    Currency::query()->delete();
    Currency::create(['active' => true, 'is_default' => true, 'name' => 'NOK', 'code' => 'NOK', 'symbol' => 'kr', 'rate' => 1.0]);

    $arParcel = PostNordParcelCalculator::calculateParcelForCart([$obPositionStub], [
        'postnord_default_weight_grams'    => 500,
        'postnord_packaging_weight'        => 20,
        'postnord_box_length'              => 250,
        'postnord_box_width'               => 180,
        'postnord_box_height'              => 120,
        'postnord_envelope_enabled'        => true,
        'postnord_envelope_length'         => 180,
        'postnord_envelope_width'          => 120,
        'postnord_envelope_height'         => 40,
        'postnord_parcel_letter_max_price' => 1000,
    ]);

    expect($arParcel['weight'])->toBe(150); // 130g real line weight + 20g tare (NOT 1020g fallback!)
    expect($arParcel['packaging'])->toBe('box'); // 220x160x90mm lamp exceeded 180x120x40mm envelope!
    expect($arParcel['length'])->toBe(25);
    expect($arParcel['cart_total_nok'])->toBe(50.0); // 2 * 25.0 from offer->price_value!
});
