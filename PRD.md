# Product Requirements Document: PostNord Pickup Locations Plugin for Lovata.Shopaholic

**Author:** Manus AI
**Date:** March 27, 2026
**Version:** 1.2 (Added QA, Testing & Frontend Architecture)
**Target System:** OctoberCMS v4.2 with Lovata.Shopaholic
**Target Stores:** nailscosmetics.lv, nailscosmetics.no
**Languages:** lv, ru, en, no

---

## 1. Executive Summary

This document defines the requirements for a new OctoberCMS plugin that integrates PostNord pickup point (service point) selection into the Lovata.Shopaholic checkout flow. The plugin will allow customers on `nailscosmetics.no` (and potentially `.lv` in the future) to select a PostNord service point as their delivery destination when they choose the PostNord shipping method during checkout.

The current store uses a hardcoded, theme-driven approach for DPD pickup points in Latvia, which violates DRY and SRP principles and is unmaintainable at scale. This plugin will replace that pattern with a proper, backend-configurable, API-driven solution that strictly follows Lovata coding standards, leverages the `Lovata.Toolbox` 3-layer caching infrastructure, integrates with `Lovata.OrdersShopaholic` property storage, and enforces strict QA standards using Pest, PHPStan, PHPMD, Pint, and Larajax.

---

## 2. Architecture Decisions

### 2.1 Single-Carrier vs. Agnostic Plugin Architecture

A key architectural decision is whether to build a single "Agnostic Shipping Hub" plugin or individual plugins per carrier (e.g., one for PostNord, one for DPD). 

**Decision: Single-Carrier-per-Plugin Architecture**

We will follow the single-carrier approach (`logingrupa/oc-postnordshipping-plugin`). This decision is based on deep analysis of the Shopaholic ecosystem and e-commerce best practices:

1. **The "Lovata Way":** While Lovata uses an agnostic abstraction for payments (`oc-omnipay-shopaholic-plugin`), they use a carrier-specific approach for shipping. For example, Lovata built `oc-shipnx-shopaholic-plugin` specifically for the Shipnx carrier.
2. **Lack of Common Interfaces:** Unlike payment gateways that share standard authorize/capture methods, shipping carriers have wildly different APIs, authentication methods, service point data structures, and routing rules.
3. **Modularity:** Separate plugins allow you to install only what you need. If you stop using PostNord, you simply uninstall the PostNord plugin without affecting DPD or Omniva.
4. **Maintainability:** An agnostic plugin becomes a monolithic bottleneck. When one carrier updates their API, the entire "Hub" plugin requires testing and deployment.

### 2.2 Order Data Storage Strategy

When a customer selects a PostNord pickup point and places an order, we need to store this selection in the database.

**Decision: Use Lovata's `$property` Array via `OrderProperty`**

The `Lovata.OrdersShopaholic` plugin provides a built-in mechanism for storing custom order metadata without modifying core database tables:

1. The plugin defines custom properties using the `OrderProperty` model (stored in `lovata_orders_shopaholic_addition_properties`).
2. When an order is created, the selected pickup point data is passed into the Order model's `$property` array (a JSON column).
3. We will store the following structured data on the order:
   - `postnord_service_point_id`
   - `postnord_service_point_name`
   - `postnord_service_point_address`

This is the official, native Shopaholic way to attach custom data to orders, ensuring it automatically appears in the backend order view.

### 2.3 Caching Strategy (Lovata.Toolbox)

The user asked: *"Lovata caches in DB, if we do not store in DB what and how we will cache?"*

**Decision: Database Table + 3-Layer Toolbox Cache**

Analysis of `Lovata.Toolbox` reveals a 3-layer caching architecture:
1. **Layer 1: Database** - The absolute source of truth (`getIDListFromDB()`).
2. **Layer 2: CCache (Persistent)** - Laravel's cache backend (Redis/File) stores ID lists permanently across requests via `CCache::forever()`.
3. **Layer 3: In-Memory Array** - The `$arCachedList` array prevents multiple CCache hits during a single request lifecycle.

Because Lovata's `AbstractStoreWithParam` requires a database query to function properly, we **must** use a database table. The plugin will:
1. Create a `logingrupa_postnord_service_points` database table.
2. Query the PostNord API and save/update the results in this local table.
3. Use a Store class extending `AbstractStoreWithParam` to fetch the IDs from the database.
4. Let `Lovata.Toolbox` handle the heavy lifting of caching those IDs in Redis/File and Memory.

---

## 3. QA & Testing Requirements

To ensure high maintainability and prevent regressions, the plugin must adhere to the Logingrupa QA standards established in `logingrupa/oc-campaignpricing-plugin`.

### 3.1 QA Pipeline (Makefile & Composer Scripts)

The project must include a complete QA pipeline executable via `composer qa` or `make all`.

**Required Tooling:**
1. **Pint (`pint.json`):** PSR-12 code style enforcement.
2. **PHPStan (`phpstan.neon`):** Level 10 strict static analysis, utilizing Larastan and `phpstan-baseline.neon` for legacy exclusions.
3. **PHPMD (`phpmd.xml`):** Lovata Group ruleset enforcing Hungarian notation (minimum variable length 4 to allow `$ar`, `$ob`, `$s` prefixes) and cyclomatic complexity limits.
4. **Rector (`rector.php`):** Automated refactoring to PHP 8.4 standards, enforcing `declare(strict_types=1)`.

**Composer Scripts:**
```json
"scripts": {
    "test": "../../../../vendor/bin/pest --configuration phpunit.xml",
    "analyse": "../../../../vendor/bin/phpstan analyse --configuration=phpstan.neon",
    "baseline": "../../../../vendor/bin/phpstan analyse --configuration=phpstan.neon --generate-baseline=phpstan-baseline.neon",
    "phpmd": "../../../../vendor/bin/phpmd classes,components,Plugin.php text phpmd.xml",
    "pint": "../../../../vendor/bin/pint . --config=pint.json",
    "pint-test": "../../../../vendor/bin/pint . --config=pint.json --test",
    "rector-dry": "../../../../vendor/bin/rector process --config=rector.php --dry-run",
    "rector": "../../../../vendor/bin/rector process --config=rector.php",
    "qa": ["@pint-test", "@analyse", "@phpmd", "@test"]
}
```

### 3.2 Automated Testing (Pest & PHPUnit)

The plugin must be fully testable using Pest 4.x / PHPUnit 12.

**Test Infrastructure:**
- A base `PostNordTestCase.php` extending `Illuminate\Foundation\Testing\TestCase`.
- Must implement OctoberCMS test concerns: `InteractsWithAuthentication`, `PerformsMigrations`, `PerformsRegistrations`.
- Must use an in-memory SQLite database (`:memory:`) and `array` cache/session drivers for fast, isolated execution.
- Must implement `tearDown()` with `flushModelEventListeners()` to prevent state leakage between tests.

**Test Coverage Requirements (`tests/unit/`):**
- **API Client:** Mock the HTTP responses to test `PostNordClient` parsing logic.
- **Stores:** Test `ServicePointListStore` caching behavior.
- **Models:** Test validation and database insertion.
- **Components:** Test the AJAX handler returns the expected partial payload.

---

## 4. Frontend & JavaScript Architecture

The frontend implementation will replace the current hardcoded DPD theme approach with a dynamic, AJAX-driven component.

### 4.1 Larajax Integration

OctoberCMS v4.1+ utilizes Larajax as its core AJAX framework. The plugin must leverage this natively.

**Component Handler:**
The `PostNordLocator` component will expose an `onGetServicePoints` handler that receives a postal code, queries the Store/API, and returns a rendered partial.

**Vanilla JavaScript + JSDoc:**
To keep the plugin lightweight and avoid npm build steps, the frontend logic will be written in modern Vanilla JavaScript (ES6+), heavily annotated with JSDoc for type safety.

```javascript
/**
 * Triggers the Larajax request to fetch PostNord service points.
 * 
 * @param {string} sPostalCode - The user-entered postal code
 * @param {HTMLElement} obContainer - The DOM element to update
 * @returns {void}
 */
function fetchPostNordPoints(sPostalCode, obContainer) {
    if (!sPostalCode || sPostalCode.length < 4) return;
    
    // Utilize OctoberCMS native jax object
    jax.request('PostNordLocator::onGetServicePoints', {
        data: { postal_code: sPostalCode },
        update: { 'PostNordLocator::service-points': obContainer.id }
    });
}
```

### 4.2 Progressive Disclosure UI

1. The checkout theme will use Shopaholic's `ShippingTypeList` to render available shipping methods.
2. If a method has `is_postnord == true`, the theme renders the `{% component 'PostNordLocator' %}`.
3. The component initially displays only a postal code input field.
4. Upon entry (debounced `keyup` event), the vanilla JS triggers the Larajax request.
5. The server returns a list of 5-10 nearby service points as radio buttons.
6. User selection is saved to the session/cart via a secondary AJAX request.

---

## 5. Research Findings: PostNord Data Sources

### 5.1 Available Data Access Methods

PostNord provides multiple methods for accessing service point data through their developer portal [1]. 

| Approach | Description | Pros | Cons |
| --- | --- | --- | --- |
| **Service Points V5 API** | REST API at `api2.postnord.com/rest/businesslocation/v5/servicepoint/findNearestByAddress` | Always accurate; handles proximity sorting; returns opening hours. | Adds latency per request if not cached locally. |
| **Find Diff API** | REST API for retrieving all service points from PostNord's data sources. | Enables a full local cache sync. | Requires scheduled sync (cron); data may be stale. |
| **Static JSON File** | **Does not exist.** PostNord does not offer a static JSON download. | N/A | N/A |

### 5.2 API Response Structure

Based on the PostNord API documentation [2], each service point object in the response contains:

```json
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
```

---

## 6. Technical Architecture

### 6.1 Plugin Identity

| Property | Value |
| --- | --- |
| **Plugin Namespace** | `Logingrupa\PostNordShipping` |
| **GitHub Repository** | `logingrupa/oc-postnordshipping-plugin` |
| **Dependencies** | `Lovata.OrdersShopaholic`, `Lovata.Toolbox` |
| **OctoberCMS Version** | 4.2+ |

### 6.2 Directory Structure

```text
plugins/logingrupa/postnordshipping/
├── Plugin.php
├── plugin.yaml
├── composer.json
├── Makefile
├── pint.json
├── phpmd.xml
├── phpstan.neon
├── phpunit.xml
├── rector.php
├── assets/
│   └── js/
│       └── postnord-locator.js                # Vanilla JS with JSDoc
├── classes/
│   ├── api/
│   │   └── PostNordClient.php                 # SRP: API communication only
│   ├── event/
│   │   ├── order/
│   │   │   └── ExtendOrderModel.php           # Registers order properties
│   │   └── shippingtype/
│   │       ├── ExtendShippingTypeFieldsHandler.php
│   │       └── ExtendShippingTypeModel.php
│   └── store/
│       └── servicepoint/
│           └── ServicePointListStore.php      # Extends AbstractStoreWithParam
├── components/
│   └── PostNordLocator.php                    # Frontend AJAX component
├── lang/
│   ├── en/lang.php
│   ├── lv/lang.php
│   ├── ru/lang.php
│   └── no/lang.php
├── models/
│   ├── Settings.php                           # Backend API key settings
│   └── ServicePoint.php                       # DB Model for local cache
├── tests/
│   ├── PostNordTestCase.php                   # Base test setup
│   └── unit/                                  # Pest test files
└── updates/
    ├── version.yaml
    ├── create_service_points_table.php        # Creates local DB table
    ├── create_order_properties.php            # Seeds OrderProperty records
    └── extend_shipping_types_table.php
```

---

## 7. Functional Requirements (v1)

### 7.1 Backend Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| BE-01 | Admin can configure PostNord API key in Settings > PostNord Shipping. | Must |
| BE-02 | Admin can mark a Shipping Type as "Is PostNord Pickup" via a checkbox. | Must |
| BE-03 | Selected PostNord pickup point data displays on the Order details page using native Shopaholic order properties. | Must |

### 7.2 Frontend Checkout Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| FE-01 | When a PostNord shipping method is selected, a postal code input field appears. | Must |
| FE-02 | User enters postal code; Larajax request fetches the 5-10 nearest service points. | Must |
| FE-03 | Display service points with Name, Street, City, and distance. | Must |
| FE-04 | User selects a point via radio button; selection is saved to session/cart. | Must |
| FE-05 | Upon order placement, the selected service point data is saved to the Order `$property` array. | Must |
| FE-06 | Multi-language support (lv, ru, en, no) for all frontend labels and error messages. | Must |

### 7.3 Data & Caching Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| DC-01 | API responses must be saved to the local `logingrupa_postnord_service_points` database table. | Must |
| DC-02 | Queries must utilize `Lovata.Toolbox` `AbstractStoreWithParam` to cache database lookups. | Must |
| DC-03 | If a requested postal code is not in the local DB, fetch from API, save to DB, and clear cache. | Must |

---

## 8. Migration Plan from DPD (Theme-Driven) to PostNord (Plugin-Driven)

The current DPD implementation is a massive block of hardcoded HTML in the theme:
```html
<div class="form-check single-method check-box form-check-inline">
    <input class="form-check-input" type="radio" name="shipping_type_id" value="6" id="sipping-type-dpd-latvia" data-shipping-dpd-latvia="" data="dpd-latvia" required="">
    <label class="form-check-label" for="sipping-type-dpd-latvia"> Saņemt pakomātā - uz pakubodi (Latvija, Lietuva, Igaunija) - kr4,-</label>
</div>
<!-- Followed by hundreds of hardcoded options -->
```

**The New Workflow:**
1. The theme loop will render shipping methods dynamically using the Shopaholic `ShippingTypeList` component.
2. The theme will check if the current shipping method has `is_postnord == true`.
3. If true, it renders the `{% component 'PostNordLocator' %}` partial.
4. The component handles the AJAX lookup and progressive disclosure of the pickup points, keeping the DOM light and fast.

---

## 9. References

[1] PostNord Developer Portal: https://developer.postnord.com/
[2] PostNord Service Points API: https://developer.postnord.com/apis/details?systemName=location-v5-servicepoints
[3] Shopaholic Extending Documentation: https://shopaholic.one/docs#/modules/shipping-type/extending/extending
[4] Lovata Toolbox GitHub Repository: https://github.com/oc-shopaholic/oc-toolbox-plugin
[5] Lovata Orders Shopaholic GitHub Repository: https://github.com/oc-shopaholic/oc-orders-shopaholic-plugin
[6] Larajax Documentation: https://docs.octobercms.com/4.x/cms/ajax/introduction.html
