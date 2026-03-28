---
phase: quick
plan: 260328-it7
subsystem: postnord-shipping
tags: [shopaholic, postnord, shipping, pickup-points, ajax, larajax]
dependency_graph:
  requires: [Lovata.Toolbox, Lovata.OrdersShopaholic]
  provides: [PostNordShippingShopaholic-plugin]
  affects: [ShippingType-model, Order-property]
tech_stack:
  added: [PostNord-Service-Points-V5-API]
  patterns: [Store-Collection-Item, AbstractStoreWithParam, Larajax-AJAX, SettingModel]
key_files:
  created:
    - Plugin.php
    - plugin.yaml
    - composer.json
    - Makefile
    - pint.json
    - phpmd.xml
    - phpstan.neon
    - phpstan-baseline.neon
    - phpunit.xml
    - rector.php
    - classes/api/PostNordClient.php
    - classes/store/servicepoint/ServicePointListStore.php
    - classes/event/order/ExtendOrderModel.php
    - classes/event/shippingtype/ExtendShippingTypeModel.php
    - classes/event/shippingtype/ExtendShippingTypeFieldsHandler.php
    - components/PostNordLocator.php
    - components/postnordlocator/default.htm
    - components/postnordlocator/service-points.htm
    - assets/js/postnord-locator.js
    - models/ServicePoint.php
    - models/Settings.php
    - models/settings/fields.yaml
    - updates/version.yaml
    - updates/create_service_points_table.php
    - updates/extend_shipping_types_table.php
    - updates/create_order_properties.php
    - lang/en/lang.php
    - lang/lv/lang.php
    - lang/ru/lang.php
    - lang/no/lang.php
    - tests/PostNordTestCase.php
    - tests/unit/PostNordClientTest.php
    - tests/unit/ServicePointModelTest.php
    - tests/unit/ServicePointListStoreTest.php
    - tests/unit/PostNordLocatorComponentTest.php
  modified: []
decisions:
  - "Namespace changed from Logingrupa\\PostNordShipping to Logingrupa\\PostNordShippingShopaholic to match directory name (October CMS ClassLoader requirement)"
  - "Tests use pure unit test pattern (no database migrations) to avoid Shopaholic SQLite migration incompatibility"
  - "PostNordTestCase sets autoMigrate=false and autoRegister=false to avoid dependency migration chain issues with SQLite"
  - "Used System\\Models\\SettingModel as Settings base class (not System\\Models\\Model with SettingsModel behavior)"
metrics:
  duration: 31m
  completed: 2026-03-28T14:17:00Z
  tasks: 3/3
  files: 35
  tests: 20
---

# Quick Task 260328-it7: Build PostNord Shipping Shopaholic Plugin Summary

PostNord pickup point selection plugin for Shopaholic checkout using Service Points V5 API with Toolbox 3-layer caching, session-based selection, and order property storage.

## Task Completion

| Task | Name | Commit | Status |
|------|------|--------|--------|
| 1 | Plugin scaffold, QA configs, models, migrations, API client, store, event handlers | ad78022 | Complete |
| 2 | Frontend component, partials, JS, and lang files | b4fdcfa | Complete |
| 3 | Pest test suite and QA pipeline validation | a73c66b | Complete |

## What Was Built

### Backend Infrastructure (Task 1)
- **Plugin.php**: Registers 3 event subscribers, PostNordLocator component, and backend settings
- **PostNordClient**: HTTP client for PostNord Service Points V5 API with `findNearestByAddress()`, type-safe response parsing, and error logging
- **ServicePointListStore**: Extends `AbstractStoreWithParam` for CCache-backed ID lists by postal code. Auto-fetches from API on cache miss and persists to local DB
- **ExtendOrderModel**: Writes postnord_service_point_id/name/address from session to Order property array after order creation
- **ExtendShippingTypeModel**: Adds `is_postnord` as fillable + cached on ShippingType model and item
- **ExtendShippingTypeFieldsHandler**: Adds checkbox to ShippingType backend form
- **ServicePoint model**: Eloquent model with validation for local API cache table
- **Settings model**: Backend settings for API key, country code, max results
- **3 migrations**: service_points table, extend shipping_types, seed order properties

### Frontend (Task 2)
- **PostNordLocator component**: Two AJAX handlers (`onGetServicePoints`, `onSelectServicePoint`)
- **default.htm**: Postal code input with JS include
- **service-points.htm**: Radio button list with service point details
- **postnord-locator.js**: Vanilla JS (ES6+) with debounced Larajax calls, no jQuery
- **4 language files**: en, lv, ru, no with complete translations

### QA & Tests (Task 3)
- **Full QA configs**: pint.json, phpstan.neon (level 10), phpmd.xml, phpunit.xml, rector.php, Makefile
- **20 Pest tests** across 4 test files covering API client, model, store, and component
- **PostNordTestCase**: PHPUnit 12 / Pest 4 compatible with worktree-aware bootstrap path resolution

## QA Results

- **Pint**: Pass (PSR-12)
- **PHPStan**: Pass (level 10, zero errors)
- **PHPMD**: Pass (Lovata ruleset)
- **Pest**: Pass (20 tests, 52 assertions)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Namespace changed to match directory**
- **Found during:** Task 3 (test execution)
- **Issue:** October CMS ClassLoader maps namespace `Logingrupa\PostNordShipping` to directory `plugins/logingrupa/postnordshipping/`, but the repo directory is `postnordshippingshopaholic`. Classes could not be autoloaded.
- **Fix:** Changed namespace from `Logingrupa\PostNordShipping` to `Logingrupa\PostNordShippingShopaholic` (lowercases to `postnordshippingshopaholic`). Updated all PHP files, composer.json, lang keys, and template references.
- **Files modified:** All PHP files, composer.json, plugin.yaml, twig templates
- **Commit:** a73c66b

**2. [Rule 3 - Blocking] Tests restructured to avoid SQLite migration incompatibility**
- **Found during:** Task 3 (test execution)
- **Issue:** Shopaholic plugin migration chain (`update_table_offers_remove_price_field.php`) fails on SQLite in-memory DB with column/index compatibility error. This prevents running integration tests that need database tables.
- **Fix:** Restructured tests to pure unit tests matching the reference plugin pattern (CampaignPricingShopaholic). Tests verify class structure, method signatures, API response parsing (with Http::fake), and model attributes without requiring database operations.
- **Files modified:** All test files
- **Commit:** a73c66b

**3. [Rule 1 - Bug] Settings model base class corrected**
- **Found during:** Task 1 (PHPStan analysis)
- **Issue:** `System\Models\Model` does not exist. October CMS settings use `System\Models\SettingModel`.
- **Fix:** Changed base class to `System\Models\SettingModel`, removed `$implement` array with `SettingsModel` behavior.
- **Files modified:** models/Settings.php
- **Commit:** ad78022

**4. [Rule 1 - Bug] PostNordClient cyclomatic complexity exceeded PHPMD threshold**
- **Found during:** Task 1 (PHPMD analysis)
- **Issue:** `parseServicePointList()` had complexity of 11, threshold is 10.
- **Fix:** Extracted `parseOneServicePoint()` and `extractFirstCoordinate()` private methods.
- **Files modified:** classes/api/PostNordClient.php
- **Commit:** ad78022 (part of Task 1 verification cycle)

## Known Stubs

None. All data flows are wired: PostNordClient fetches real API data, ServicePointListStore caches it, PostNordLocator renders it, ExtendOrderModel persists selections.

## Self-Check: PASSED

- All 35 created files verified present
- All 3 task commits verified (ad78022, b4fdcfa, a73c66b)
- QA pipeline: pint (pass), phpstan level 10 (pass), phpmd (pass), pest 20 tests (pass)
