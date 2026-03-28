# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**PostNordShippingShopaholic Plugin** (`Logingrupa\PostNordShipping`)

A Lovata Shopaholic ecosystem plugin that integrates PostNord pickup point (service point) selection into the checkout flow. Customers select a PostNord shipping method, enter a postal code, and pick a nearby service point — all via Larajax AJAX. Selected points are stored on orders via Shopaholic's native `OrderProperty` system.

**Target stores:** nailscosmetics.no (primary), nailscosmetics.lv (future)
**Languages:** lv, ru, en, no

## Build & QA Commands

All QA tooling lives in the parent project's `vendor/bin/`. Run commands from the plugin directory.

```bash
# Full QA pipeline (pint → phpstan → phpmd → tests)
make all
composer qa

# Individual commands
make test              # or: composer test
make analyse           # or: composer analyse (PHPStan level 10)
make phpmd             # or: composer phpmd
make pint              # or: composer pint (auto-fix)
make pint-test         # or: composer pint-test (check only)
make rector-dry        # or: composer rector-dry (preview refactors)
make rector            # or: composer rector (apply refactors)

# Generate PHPStan baseline for legacy suppressions
make baseline          # or: composer baseline
```

**Test runner:** Pest 4.x on PHPUnit 12, SQLite in-memory, array cache/session drivers.
**Bootstrap:** `../../../modules/system/tests/bootstrap.php` (OctoberCMS test harness).

## Architecture

This plugin follows the same patterns as `logingrupa/campaignpricingshopaholic` (the QA reference standard).

### Lovata Toolbox 3-Layer Caching

All data access follows: **Store → Collection → Item** (never direct Eloquent in templates).

1. **DB** — `logingrupa_postnord_service_points` table (local cache of PostNord API responses)
2. **CCache** — Redis/file cache stores ID lists via `CCache::forever()`, tagged for invalidation
3. **In-Memory** — `$arCachedList` prevents duplicate cache hits per request

`ServicePointListStore` extends `AbstractStoreWithParam` — keyed by postal code. If a postal code isn't in the local DB, the store fetches from the PostNord API, saves to DB, and clears cache.

### Key Classes

| Layer | Class | Role |
|-------|-------|------|
| API | `PostNordClient` | HTTP communication with PostNord Service Points V5 API |
| Store | `ServicePointListStore` | `AbstractStoreWithParam` — cached ID lists by postal code |
| Model | `ServicePoint` | Eloquent model for local API cache table |
| Model | `Settings` | Backend settings (API key configuration) |
| Component | `PostNordLocator` | AJAX component — `onGetServicePoints` handler |
| Event | `ExtendOrderModel` | Saves selected service point to order `$property` array |
| Event | `ExtendShippingTypeModel` | Adds `is_postnord` flag to shipping type model |
| Event | `ExtendShippingTypeFieldsHandler` | Backend checkbox field for "Is PostNord Pickup" |

### Frontend Flow

1. Checkout renders shipping methods via `ShippingTypeList` component
2. If shipping type has `is_postnord == true`, theme renders `{% component 'PostNordLocator' %}`
3. Component shows postal code input → debounced `keyup` triggers Larajax request
4. `onGetServicePoints` returns rendered partial with radio buttons (5-10 nearest points)
5. User selects a point → secondary AJAX saves to session/cart
6. On order placement → `ExtendOrderModel` writes to `OrderProperty`

### Order Data Storage

Selected pickup points are stored as native Shopaholic order properties (not custom columns):
- `postnord_service_point_id`
- `postnord_service_point_name`
- `postnord_service_point_address`

These appear automatically in the backend order detail view.

## Dependencies

```
Lovata.Toolbox ^2.2
Lovata.OrdersShopaholic ^1.33
```

## Conventions

### PHP Standards

- **Hungarian notation** (Lovata.Toolbox standard): `$obItem`, `$arList`, `$iCount`, `$sSlug`, `$bIsActive`, `$fPrice`
- **`declare(strict_types=1)`** at file top (enforced by Rector)
- **`#[\Override]`** attribute on all parent method overrides
- **PSR-12** code style (enforced by Pint)
- **PHPStan level 10** with Larastan
- **PHPMD** Lovata ruleset: min variable name 4 chars (allows `$ar`, `$ob`, `$s` prefixes)
- Translatable strings via `lang.php` keys — never hardcode user-facing text
- Namespace: `Logingrupa\PostNordShipping` (use `Logingrupa` not `LoginGrupa`)

### JavaScript

- Vanilla JS (ES6+) with JSDoc type annotations — no jQuery, no npm build step
- Larajax for AJAX: `jax.request('PostNordLocator::onGetServicePoints', { ... })`

### Plugin Extension Pattern

Plugins extend Shopaholic via event listeners in `Plugin.php::boot()`:
- `Event::subscribe(HandlerClass::class)` for model handlers
- `Model::extend()` / `addDynamicMethod()` for model extensions
- Never subclass upstream models directly

### Test Pattern

- Base test case: `PostNordTestCase.php` extending `Illuminate\Foundation\Testing\TestCase`
- Must implement OctoberCMS concerns: `InteractsWithAuthentication`, `PerformsMigrations`, `PerformsRegistrations`
- `tearDown()` must call `flushModelEventListeners()` to prevent state leakage
- Mock HTTP responses for API client tests — never hit real PostNord API in tests

## Reference

- **PRD:** `PRD.md` in this repo — full requirements, API response structures, migration plan
- **QA reference plugin:** `plugins/logingrupa/campaignpricingshopaholic/` — identical QA tooling setup
- **PostNord API:** Service Points V5 at `api2.postnord.com/rest/businesslocation/v5/servicepoint/findNearestByAddress`
- **Larajax docs:** https://docs.octobercms.com/4.x/cms/ajax/introduction.html
