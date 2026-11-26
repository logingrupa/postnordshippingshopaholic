# Logingrupa.PostNordShippingShopaholic

Shopaholic shipping processor and checkout locator (`Logingrupa\PostNordShippingShopaholic`, package `logingrupa/oc-postnordshipping-plugin`). Supports **PostNord Norway** (OAuth2 + optional live dynamic pricing via B2C Products API) and **PostNord Nordic** (API Key Service Points v5). Extends root `CLAUDE.md`.

- **Stores**: `nailscosmetics.no` (primary), `.lv` / `.lt` (supported); **Languages**: `en`, `lv`, `nb-no`, `ru` (`lang/nb-no/`, not `lang/no`)
- **Requires**: `Lovata.Toolbox ^2.2`, `Lovata.OrdersShopaholic ^1.33`

## Dual API Modes & Pricing
- Configured per `ShippingType` via `api_class = PostNordShippingProcessor` and `ShippingType::$property` (no global settings API key or `is_postnord` flag).
- **Norway mode (`postnord_api_mode = norway`)**: OAuth2 (`developer.postnord.no`, `api2.postnord.no` / sandbox `atapi2.postnord.no`), B2C Products API (`consignment.colli`).
  - **Flat-rate (default)** when `postnord_dynamic_pricing` is off.
  - **Opt-in live dynamic pricing**: `PostNordParcelCalculator` + `PostNordCartMeasurer` + `PostNordMeasureHelper` compute cart weight/box/bubble-envelope dims (Shopaholic units -> cm); `PostNordNorwayProductParser` exposes delivery options (`S` Parcel Locker, `Z` Service Point, `B` Home Small, `H` Home, `Q` Home Attended); `PostNordPriceCalculator` applies VAT/markup/rounding and NOK conversion (`1.0x` on NOK shops, `kr X,-`); `PostNordSessionQuoteStore` stores one quote bucket per shipping type (holding `postal_code` + `parcel_sig` + point quotes); `PostNordShippingProcessor::getPrice()` re-quotes automatically on parcel/weight changes; `PostNordOrderValidator` rejects tampered/stale submitted selections at order creation.
- **Nordic mode (`postnord_api_mode = nordic`)**: Service Points v5 (`api2.postnord.com` / `atapi2.postnord.com`) with API key.

## Key Classes
- `classes/api/PostNordShippingProcessor.php` - `ShippingPriceProcessorInterface`, backend fields, dynamic quote pricing & re-quote
- `classes/api/PostNordClient.php`, `PostNordNorwayClient.php`, `PostNordNorwayPayloadBuilder.php`, `PostNordNorwayProductParser.php`, `PostNordNorwayResponseParser.php` - HTTP clients & parsers
- `classes/api/PostNordCatalogPresenter.php`, `PostNordPriceCalculator.php`, `PostNordParcelCalculator.php`, `PostNordCartMeasurer.php`, `PostNordMeasureHelper.php`, `PostNordSessionQuoteStore.php`, `PostNordOrderValidator.php`, `PostNordPrefillResolver.php` - pricing, packaging, prefill & order validation
- `classes/store/servicepoint/ServicePointListStore.php` (+ `ServicePointCacheNormalizer`, `ServicePointSearchScope`) - 6h TTL scoped cache by country/mode/sandbox/search scope (DB fallback is never cached)
- `components/PostNordLocator.php` - Larajax `onGetServicePoints`, `onSelectServicePoint`, and `getPrefillData()`
- `classes/event/shippingtype/ExtendShippingTypeModel.php` & `ExtendShippingTypeFieldsHandler.php` - registers `PostNordShippingProcessor` and toggles Norway vs Nordic fields
- `classes/event/order/ExtendOrderModel.php` - writes verified PostNord metadata onto orders (skips & clears session for non-PostNord orders)

## Theme Checkout & Order Properties
- `checkout-form.htm` emits `data-shipping-partial="postnord"` when `obShippingType.api_class == 'Logingrupa\\PostNordShippingShopaholic\\Classes\\Api\\PostNordShippingProcessor'`.
- `partials/ajax/ajax_shipping_address/shipping-address-postnord.htm` + lazy Vite modules in `themes/logingrupa-naisstore/src/modules/checkout-postnord/*` handle delivery cards, pickup dropdown, home street/city inputs, session sync (`postnordSyncPending`/`postnordSyncFailed`), and browser cache.
- Order properties (`lovata_orders_shopaholic_addition_properties`, Shipping address tab): `postnord_service_point_id`, `postnord_service_point_name`, `postnord_service_point_address`, `postnord_product_code`, `postnord_product_name` (`1.0.6` migration `add_product_order_properties.php`).

## Quality Gates & Conventions
```bash
php artisan plugin:test Logingrupa.PostNordShippingShopaholic   # from root
composer qa   # from plugin dir: pint-test -> phpstan L10 -> phpmd -> pest
```
- `declare(strict_types=1)`, `#[\Override]`, PSR-12, PHPStan level 10, PHPMD Lovata ruleset.
- Tests extend `PostNordTestCase` (SQLite in-memory; `tearDown()` flushes model event listeners; mock all HTTP calls).
- `PRD.md` is a historical v1 pickup-only doc (superseded by this file).
