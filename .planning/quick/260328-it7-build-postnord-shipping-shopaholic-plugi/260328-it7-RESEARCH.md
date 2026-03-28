# Quick Task: Build PostNord Shipping Shopaholic Plugin - Research

**Researched:** 2026-03-28
**Domain:** OctoberCMS v4 Lovata Shopaholic plugin development, PostNord API integration
**Confidence:** HIGH

## Summary

This plugin follows well-established patterns from the reference plugin `logingrupa/campaignpricingshopaholic`. All QA tooling (Pest 4, PHPStan 10, PHPMD, Pint, Rector) exists in the parent project's `vendor/bin/`. The Lovata Toolbox Store-Collection-Item caching architecture, Model extension via events, and Settings model pattern are all thoroughly documented by examining the existing codebase.

The PostNord Service Points V5 API is a simple REST GET with API key authentication. Order property data flows automatically through Shopaholic's `MakeOrder` component when form fields use `name="property[key]"` naming. The ShippingType model extension pattern (adding `is_postnord` boolean) is identical to the existing `external_id` extension in `logingrupa/extendshopaholic`.

**Primary recommendation:** Follow the `campaignpricingshopaholic` reference plugin structure exactly for QA config, TestCase, Plugin.php, composer.json, and Makefile. Use `AbstractStoreWithParam` keyed by postal code for the service point cache layer.

## Project Constraints (from CLAUDE.md)

- Hungarian notation mandatory: `$obItem`, `$arList`, `$iCount`, `$sSlug`, `$bIsActive`, `$fPrice`
- `declare(strict_types=1)` on all files (enforced by Rector)
- `#[\Override]` attribute on all parent method overrides
- PSR-12 via Pint, PHPStan level 10, PHPMD Lovata ruleset
- Namespace: `Logingrupa\PostNordShipping` (not `LoginGrupa`)
- Vanilla JS + Larajax only, no jQuery, no npm build step
- Translatable strings via `lang.php` keys, never hardcode user-facing text
- Languages: lv, ru, en, no
- Test runner: Pest 4.x on PHPUnit 12, SQLite in-memory, array cache/session
- Mock HTTP responses for API tests, never hit real PostNord API

## Architecture Patterns

### Reference Plugin Structure (from campaignpricingshopaholic)

Every config file, Makefile, and QA setup must mirror the reference plugin exactly, with namespace/name substitutions.

### Plugin.php Pattern

```php
<?php namespace Logingrupa\PostNordShipping;

use Event;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public $require = [
        'Lovata.Toolbox',
        'Lovata.OrdersShopaholic',
    ];

    #[\Override]
    public function pluginDetails(): array
    {
        return [
            'name'        => 'logingrupa.postnordshipping::lang.plugin.name',
            'description' => 'logingrupa.postnordshipping::lang.plugin.description',
            'author'      => 'Logingrupa',
            'icon'        => 'icon-map-marker',
        ];
    }

    public function boot(): void
    {
        Event::subscribe(ExtendShippingTypeFieldsHandler::class);
        (new ExtendShippingTypeModel())->subscribe();
        (new ExtendOrderModel())->subscribe();
    }

    #[\Override]
    public function registerComponents(): array
    {
        return [
            PostNordLocator::class => 'PostNordLocator',
        ];
    }

    #[\Override]
    public function registerSettings(): array
    {
        return [
            'postnord-settings' => [
                'label'       => 'logingrupa.postnordshipping::lang.settings.label',
                'description' => 'logingrupa.postnordshipping::lang.settings.description',
                'category'    => 'lovata.shopaholic::lang.tab.settings',
                'icon'        => 'icon-map-marker',
                'class'       => Settings::class,
                'order'       => 500,
            ],
        ];
    }
}
```

### Store Pattern (AbstractStoreWithParam)

```php
class ServicePointListStore extends AbstractStoreWithParam
{
    protected static $instance;

    // $this->sValue = postal code string
    #[\Override]
    protected function getIDListFromDB(): array
    {
        return ServicePoint::where('postal_code', $this->sValue)
            ->pluck('id')
            ->all();
    }
}
```

Key details from `AbstractStoreWithParam`:
- Singleton via `October\Rain\Support\Traits\Singleton`
- `get($sFilterValue)` checks in-memory `$arCachedList` -> CCache -> DB
- `clear($sFilterValue)` flushes both CCache and in-memory
- `$this->sValue` holds the param (postal code)
- `getIDListFromDB()` is the only method to override
- Cache tags default to `[static::class]` (class name)

### ShippingType Extension Pattern (from extendshopaholic)

Two classes needed:

**Model extension** -- adds `is_postnord` to fillable + cached:
```php
class ExtendShippingTypeModel
{
    public function subscribe(): void
    {
        ShippingType::extend(function ($obElement) {
            $obElement->fillable[] = 'is_postnord';
            $obElement->addCachedField(['is_postnord']);
        });
    }
}
```

**Field handler** -- adds checkbox to backend form:
```php
class ExtendShippingTypeFieldsHandler extends AbstractBackendFieldHandler
{
    #[\Override]
    protected function extendFields($obWidget): void
    {
        $obWidget->addFields([
            'is_postnord' => [
                'label' => 'logingrupa.postnordshipping::lang.field.is_postnord',
                'span' => 'left',
                'type' => 'checkbox',
                'tab' => 'lovata.toolbox::lang.tab.settings',
            ],
        ]);
    }

    #[\Override]
    protected function getModelClass(): string
    {
        return ShippingType::class;
    }

    #[\Override]
    protected function getControllerClass(): string
    {
        return ShippingTypes::class;
    }
}
```

Migration adds `is_postnord` boolean column to `lovata_orders_shopaholic_shipping_types`.

### Order Property Storage Pattern

Order properties flow via MakeOrder component (`components/MakeOrder.php` line 199-203):
```php
$arOrderData['property'] = array_merge(
    $arOrderData['property'],
    $this->arUserData,
    $this->arBillingAddressOrder,
    $this->arShippingAddressOrder
);
```

The `property` field is `$jsonable` on Order model, and `SetPropertyAttributeTrait` merges new key-value pairs. Frontend form fields named `property[postnord_service_point_id]` automatically flow into the order's property JSON column.

The `ExtendOrderModel` event handler should listen to `shopaholic.order.before_create` (or use `Order::extend()` with `beforeCreate` event) to inject the selected service point data from session into `$arOrderData['property']`.

**OrderProperty seeder pattern** (for backend display):
```php
OrderProperty::create([
    'active' => true,
    'name' => 'logingrupa.postnordshipping::lang.order_property.service_point_name',
    'code' => 'postnord_service_point_name',
    'slug' => 'postnord_service_point_name',
    'type' => 'input',
    'settings' => ['tab' => 'PostNord'],
    'sort_order' => 100,
]);
```

### Settings Model Pattern

```php
class Settings extends CommonSettings
{
    const SETTINGS_CODE = 'logingrupa_postnord_settings';
    public $settingsCode = 'logingrupa_postnord_settings';
    public $settingsFields = 'fields.yaml';
}
```

With `models/settings/fields.yaml`:
```yaml
tabs:
    fields:
        api_key:
            tab: logingrupa.postnordshipping::lang.settings.tab_api
            label: logingrupa.postnordshipping::lang.settings.api_key
            type: sensitive
            span: full
        country_code:
            tab: logingrupa.postnordshipping::lang.settings.tab_api
            label: logingrupa.postnordshipping::lang.settings.country_code
            type: dropdown
            options:
                NO: Norway
                SE: Sweden
                DK: Denmark
                FI: Finland
            default: 'NO'
            span: left
        result_limit:
            tab: logingrupa.postnordshipping::lang.settings.tab_api
            label: logingrupa.postnordshipping::lang.settings.result_limit
            type: number
            default: 10
            span: left
```

### Component AJAX Pattern (Larajax)

```php
class PostNordLocator extends ComponentBase
{
    #[\Override]
    public function componentDetails(): array { /* ... */ }

    public function onGetServicePoints(): void
    {
        $sPostalCode = trim((string) input('postal_code'));
        // Validate, fetch from store/API, set page vars
        $this->page['arServicePointList'] = $arServicePointList;
    }

    public function onSelectServicePoint(): void
    {
        $sPointId = (string) input('service_point_id');
        // Save to session for later order creation
        Session::put('postnord_service_point', [...]);
    }
}
```

Frontend calls via Larajax:
```javascript
jax.request('PostNordLocator::onGetServicePoints', {
    data: { postal_code: sPostalCode },
    update: { 'PostNordLocator::service-points': '#postnord-results' }
});
```

### Test Base Class Pattern

Copied verbatim from `CampaignPricingTestCase` with namespace change to `Logingrupa\PostNordShipping\Tests`. Key points:
- Extends `Illuminate\Foundation\Testing\TestCase` (NOT October's PluginTestCase)
- Uses `October\Tests\Concerns\InteractsWithAuthentication`, `PerformsMigrations`, `PerformsRegistrations`
- `setUp()` calls `loadCurrentPlugin()` + `migrateModules()` + `migrateCurrentPlugin()`
- `tearDown()` calls `flushModelEventListeners()` to prevent state leakage
- `createApplication()` bootstraps the full OctoberCMS app with AuthManager singleton

### Pest Test Pattern

```php
<?php
use Logingrupa\PostNordShipping\Tests\PostNordTestCase;

uses(PostNordTestCase::class);

test('PostNordClient parses service points from API response', function () {
    // Mock HTTP, call client, assert parsed result
    Http::fake([
        'api2.postnord.com/*' => Http::response([...], 200),
    ]);
    $obClient = new PostNordClient('test-api-key', 'NO');
    $arResult = $obClient->findNearestByAddress('1528');
    expect($arResult)->toHaveCount(5);
});
```

## PostNord Service Points V5 API

**Endpoint:** `GET https://api2.postnord.com/rest/businesslocation/v5/servicepoint/findNearestByAddress`

**Authentication:** API key as query parameter `apikey`

**Key Parameters:**
| Param | Required | Description |
|-------|----------|-------------|
| `apikey` | Yes | API key from PostNord developer portal |
| `countryCode` | Yes | ISO 2-letter country code (NO, SE, DK, FI) |
| `postalCode` | Yes | Postal code to search near |
| `numberOfServicePoints` | No | Max results (default 10) |
| `typeId` | No | Filter by service point type |

**Response structure** (from PRD):
```json
{
  "servicePointInformationResponse": {
    "servicePoints": [
      {
        "servicePointId": "123456",
        "name": "Coop Extra Moss",
        "visitingAddress": {
          "streetName": "Gjeddeveien",
          "streetNumber": "18",
          "postalCode": "1528",
          "city": "MOSS",
          "countryCode": "NO"
        },
        "coordinates": [
          { "northing": 59.4340, "easting": 10.6590, "srId": "EPSG:4326" }
        ]
      }
    ]
  }
}
```

**Confidence:** MEDIUM -- response structure from PRD, not verified against live API. The PostNordClient should be resilient to field variations.

## QA Config Files (Copy from Reference)

All files below must be copied from `campaignpricingshopaholic` with namespace/name substitutions:

| File | Key Changes |
|------|-------------|
| `composer.json` | Name: `logingrupa/oc-postnordshipping-plugin`, namespace: `Logingrupa\\PostNordShipping\\`, requires: toolbox + ordersshopaholic (not campaignsshopaholic), extra.october.plugin: `Logingrupa.PostNordShipping`, installer-name: `postnordshippingshopaholic` |
| `phpunit.xml` | Suite name: `PostNordShipping Unit Tests` |
| `phpstan.neon` | tmpDir: `../../../storage/temp/phpstan/postnordshipping`, paths include `models` dir too |
| `phpmd.xml` | Ruleset name: `PostNordShippingShopaholic`, phpmd paths must include `models` dir |
| `pint.json` | Identical |
| `rector.php` | Add `models` to paths |
| `Makefile` | Identical structure, phpmd paths add `models` |
| `plugin.yaml` | Name/description use `logingrupa.postnordshipping::lang.*` keys |

**Composer scripts** use `../../../vendor/bin/` relative path (3 levels up from plugin dir to project root). Verified: this matches the actual directory structure.

## Database Schema

### `logingrupa_postnord_service_points` table

```php
Schema::create('logingrupa_postnord_service_points', function (Blueprint $obTable) {
    $obTable->engine = 'InnoDB';
    $obTable->increments('id');
    $obTable->string('service_point_id')->index();       // PostNord ID
    $obTable->string('name');
    $obTable->string('street_name')->nullable();
    $obTable->string('street_number')->nullable();
    $obTable->string('postal_code')->index();
    $obTable->string('city')->nullable();
    $obTable->string('country_code', 2)->default('NO');
    $obTable->decimal('latitude', 10, 7)->nullable();
    $obTable->decimal('longitude', 10, 7)->nullable();
    $obTable->timestamps();

    $obTable->unique(['service_point_id', 'country_code']);
});
```

### `extend_shipping_types_add_is_postnord.php` migration

```php
Schema::table('lovata_orders_shopaholic_shipping_types', function (Blueprint $obTable) {
    $obTable->boolean('is_postnord')->default(false)->after('api_class');
});
```

### `version.yaml`

```yaml
1.0.0:
    - 'Initialize plugin'
    - create_service_points_table.php
    - extend_shipping_types_add_is_postnord.php
    - create_order_properties.php
```

## Common Pitfalls

### Pitfall 1: PHPUnit 12 setUp() Visibility
**What goes wrong:** October's `PluginTestCase` declares `setUp()` as public, conflicting with PHPUnit 12's protected signature.
**How to avoid:** Use the custom `PostNordTestCase` pattern (extends `Illuminate\Foundation\Testing\TestCase` directly), NOT October's `PluginTestCase`.

### Pitfall 2: PHPStan Level 10 with Eloquent Magic
**What goes wrong:** Dynamic properties on models, items, and collections cause PHPStan errors.
**How to avoid:** Use `phpstan-baseline.neon` for Lovata-specific magic property access. Add `universalObjectCratesClasses: [Lovata\Toolbox\Classes\Item\ElementItem]` to phpstan.neon.

### Pitfall 3: Store Singleton State in Tests
**What goes wrong:** Store singleton retains in-memory cache between tests.
**How to avoid:** `flushModelEventListeners()` in tearDown, and manually reset store singletons if testing cache behavior.

### Pitfall 4: Order Property Merge vs Replace
**What goes wrong:** `SetPropertyAttributeTrait` merges, not replaces. Setting a property key to null does not remove it.
**How to avoid:** Always set complete key-value pairs. PostNord data should be set as a merge into existing property array.

### Pitfall 5: AbstractBackendFieldHandler isNested Check
**What goes wrong:** Field handler fires for nested form widgets (e.g., relation managers), adding fields where they don't belong.
**How to avoid:** The base class already checks `$obWidget->isNested` -- just extend `AbstractBackendFieldHandler` correctly.

### Pitfall 6: Composer Script Paths
**What goes wrong:** Scripts use `../../../../vendor/bin/` (4 levels) in PRD but actual reference uses `../../../vendor/bin/` (3 levels).
**How to avoid:** Plugin sits at `plugins/logingrupa/postnordshippingshopaholic/` which is 3 levels from project root. Use `../../../vendor/bin/` as in the reference plugin.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Pest | Tests | Yes | In vendor/bin | -- |
| PHPStan | Static analysis | Yes | In vendor/bin | -- |
| PHPMD | Code quality | Yes | In vendor/bin | -- |
| Pint | Code style | Yes | In vendor/bin | -- |
| Rector | Refactoring | Yes | In vendor/bin | -- |
| PostNord API | Service points | External | V5 | Mock in tests |

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.x / PHPUnit 12 |
| Config file | `phpunit.xml` (to be created, Wave 0) |
| Quick run command | `make test` |
| Full suite command | `make all` |

### Wave 0 Gaps
- [ ] `phpunit.xml` -- test configuration
- [ ] `tests/PostNordTestCase.php` -- base test class
- [ ] `tests/unit/PostNordClientTest.php` -- API client parsing
- [ ] `tests/unit/ServicePointModelTest.php` -- model validation
- [ ] All QA config files (phpstan.neon, phpmd.xml, pint.json, rector.php, Makefile, composer.json)

## Sources

### Primary (HIGH confidence)
- Reference plugin: `plugins/logingrupa/campaignpricingshopaholic/` -- Plugin.php, composer.json, TestCase, Store, Component, all QA configs
- `plugins/lovata/toolbox/classes/store/AbstractStore.php` and `AbstractStoreWithParam.php` -- caching architecture
- `plugins/lovata/toolbox/classes/event/AbstractBackendFieldHandler.php` -- field extension pattern
- `plugins/lovata/toolbox/models/CommonSettings.php` -- Settings base class
- `plugins/lovata/ordersshopaholic/models/Order.php` -- property JSON field, fillable, SetPropertyAttributeTrait
- `plugins/lovata/ordersshopaholic/models/OrderProperty.php` -- order property registration
- `plugins/lovata/ordersshopaholic/models/ShippingType.php` -- model to extend
- `plugins/lovata/ordersshopaholic/updates/seeder_default_order_properties.php` -- seeder pattern
- `plugins/logingrupa/extendshopaholic/classes/event/shippingtype/` -- ShippingType extension pattern (fields + model)
- `plugins/lovata/ordersshopaholic/components/MakeOrder.php` lines 199-203 -- property merge flow

### Secondary (MEDIUM confidence)
- PRD.md -- PostNord API response structure (not verified against live API)
- PostNord developer portal (referenced but not fetched)

## Metadata

**Confidence breakdown:**
- Plugin structure: HIGH -- directly copied from working reference plugin
- Store/Item/Component patterns: HIGH -- verified against Lovata Toolbox source code
- ShippingType extension: HIGH -- verified against existing logingrupa/extendshopaholic implementation
- Order property flow: HIGH -- verified from MakeOrder component source and SetPropertyAttributeTrait
- PostNord API structure: MEDIUM -- from PRD, not verified against live docs
- QA tooling: HIGH -- all tools verified present in vendor/bin

**Research date:** 2026-03-28
**Valid until:** 2026-04-28
