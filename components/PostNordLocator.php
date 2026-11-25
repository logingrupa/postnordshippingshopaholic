<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\Session;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordCatalogPresenter;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordParcelCalculator;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordPrefillResolver;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordPriceCalculator;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordSessionQuoteStore;
use Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint\ServicePointListStore;
use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;

/**
 * Class PostNordLocator
 * @package Logingrupa\PostNordShippingShopaholic\Components
 *
 * AJAX component for PostNord delivery method selection (Parcel Locker,
 * Service Point, Home Small, Home Delivery), pickup point search, and live
 * weight/distance-based shipping rate updates (PostNord Norway).
 */
class PostNordLocator extends ComponentBase
{
    /**
     * Returns information about this component.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function componentDetails(): array
    {
        return [
            'name'        => 'logingrupa.postnordshippingshopaholic::lang.component.name',
            'description' => 'logingrupa.postnordshippingshopaholic::lang.component.description',
        ];
    }

    /**
     * AJAX handler: fetch delivery options and pickup points for a postal code.
     *
     * @return array<string, mixed>
     */
    public function onGetServicePoints(): array
    {
        $sPostalCode = $this->normalizePostalCode($this->readTrimmedInput('postal_code'));
        $mRequestSeq = input('request_seq');
        $iRequestSeq = is_numeric($mRequestSeq) ? (int) $mRequestSeq : null;

        if (!preg_match('/^\d{4,5}$/', $sPostalCode)) {
            $this->page['arServicePointList'] = [];
            $this->page['arDeliveryOptionList'] = [];
            $this->page['bHasSearched'] = false;

            return $this->buildPartialResponse($iRequestSeq, [], '');
        }

        Session::put('postnord_last_postal_code', $sPostalCode);

        $mShippingTypeId = input('shipping_type_id');
        $iShippingTypeId = is_numeric($mShippingTypeId) ? (int) $mShippingTypeId : null;

        /** @var ServicePointListStore $obStore */
        $obStore = ServicePointListStore::instance();
        $obShippingTypeItem = $obStore->resolvePostNordShippingTypeItem($iShippingTypeId);
        $arProperty = $this->extractShippingTypeProperty($obShippingTypeItem);
        $bDynamicPricing = PostNordPriceCalculator::isDynamicPricingEnabled($arProperty);
        $arParcel = $bDynamicPricing
            ? PostNordParcelCalculator::calculateCurrentParcel($arProperty)
            : null;
        $iWeightGrams = $arParcel !== null ? $arParcel['weight'] : null;

        $arCatalog = $obStore->getDeliveryCatalog($sPostalCode, $iShippingTypeId, $iWeightGrams, $arParcel);
        $arBuiltProducts = PostNordCatalogPresenter::buildDeliveryOptionList($arCatalog['products'], $bDynamicPricing, $arProperty);
        $arProductNameMap = $this->indexProductDisplayNames($arCatalog['products']);
        $arLoaded = PostNordCatalogPresenter::loadOrderedServicePoints($arCatalog['points'], $bDynamicPricing, $arProperty, $arProductNameMap);
        // Use array union (+) instead of array_merge() so numeric string service_point_id keys are never re-indexed
        $arAllQuotes = $arBuiltProducts['quotes'] + $arLoaded['quotes'];
        $sParcelSig = $arParcel !== null ? PostNordParcelCalculator::buildParcelSignature($arParcel) : '';

        $this->persistDynamicQuotes($bDynamicPricing, $obShippingTypeItem, $sPostalCode, $iWeightGrams, $arAllQuotes, $sParcelSig);

        $sDefaultCity = $this->resolveDefaultCity($arLoaded['points']);
        $this->page['arServicePointList'] = $arLoaded['points'];
        $this->page['arDeliveryOptionList'] = $arBuiltProducts['options'];
        $this->page['bHasSearched'] = true;

        return $this->buildPartialResponse($iRequestSeq, $arBuiltProducts['options'], $sDefaultCity);
    }

    /**
     * AJAX handler: validate and save selected PostNord delivery option / pickup
     * point to session and recalculate cart shipping totals when dynamic pricing
     * is active. Never writes unvalidated dynamic selections into session.
     *
     * @return array<string, mixed>
     */
    public function onSelectServicePoint(): array
    {
        $sPointId = $this->readTrimmedInput('service_point_id');
        $sPointName = $this->readTrimmedInput('service_point_name');
        $sPointAddress = $this->readTrimmedInput('service_point_address');
        $sProductCode = strtoupper($this->readTrimmedInput('product_code'));
        $sProductName = $this->readTrimmedInput('product_name');
        $sPostalCode = $this->normalizePostalCode($this->readTrimmedInput('postal_code'));

        $mShippingTypeId = input('shipping_type_id');
        $iShippingTypeId = is_numeric($mShippingTypeId) ? (int) $mShippingTypeId : null;

        /** @var ServicePointListStore $obStore */
        $obStore = ServicePointListStore::instance();
        $obShippingTypeItem = $obStore->resolvePostNordShippingTypeItem($iShippingTypeId);
        $arProperty = $this->extractShippingTypeProperty($obShippingTypeItem);

        if ($obShippingTypeItem instanceof ShippingTypeItem && PostNordPriceCalculator::isDynamicPricingEnabled($arProperty)) {
            return $this->handleDynamicSelection(
                $obShippingTypeItem,
                $sPointId,
                $sPointName,
                $sPointAddress,
                $sProductCode,
                $sProductName,
                $sPostalCode,
            );
        }

        $this->writeSelectionToSession($sPointId, $sPointName, $sPointAddress, $sProductCode, $sProductName);

        return ['result' => 'ok'];
    }

    /**
     * Resolve prefill postcode, street, and city for the current shopper.
     *
     * @return array{postcode: string, street: string, city: string}
     */
    public function getPrefillData(?object $obUser = null): array
    {
        return PostNordPrefillResolver::resolve($obUser);
    }

    /**
     * Validate a dynamic quote against the server bucket (including postal code)
     * BEFORE saving authoritative quote metadata into session and recalculating cart.
     *
     * @return array<string, mixed>
     */
    private function handleDynamicSelection(
        ShippingTypeItem $obShippingTypeItem,
        string $sPointId,
        string $sPointName,
        string $sPointAddress,
        string $sProductCode,
        string $sProductName,
        string $sPostalCode,
    ): array {
        $sQuoteKey = $this->pickNonEmpty($sPointId, $sProductCode !== '' ? 'product:' . $sProductCode : '');
        $iResolvedTypeId = is_numeric($obShippingTypeItem->id) ? (int) $obShippingTypeItem->id : 0;
        $arSelectedQuote = PostNordSessionQuoteStore::selectPointQuote($iResolvedTypeId, $sQuoteKey, $sPostalCode);

        if ($arSelectedQuote === null) {
            return ['result' => 'stale'];
        }

        $sEffectivePointId = str_starts_with($arSelectedQuote['service_point_id'], 'product:')
            ? ''
            : $arSelectedQuote['service_point_id'];

        $this->writeSelectionToSession(
            $sEffectivePointId,
            $this->pickNonEmpty($arSelectedQuote['service_point_name'], $sPointName),
            $this->pickNonEmpty($arSelectedQuote['service_point_address'], $sPointAddress),
            $this->pickNonEmpty($arSelectedQuote['product_code'], $sProductCode),
            $this->pickNonEmpty($arSelectedQuote['product_name'], $sProductName),
        );

        /** @var array<string, mixed> $arResponse */
        $arResponse = ['result' => 'ok'];
        $arCartData = $this->recalculateCart($obShippingTypeItem);
        if ($arCartData !== null) {
            $arResponse['cart_data'] = $arCartData;
        }

        return $arResponse;
    }

    /**
     * Return the primary string when non-empty, otherwise fall back to secondary.
     */
    private function pickNonEmpty(string $sPrimary, string $sFallback): string
    {
        return $sPrimary !== '' ? $sPrimary : $sFallback;
    }

    /**
     * Persist validated PostNord selection details into PHP session.
     */
    private function writeSelectionToSession(
        string $sPointId,
        string $sPointName,
        string $sPointAddress,
        string $sProductCode,
        string $sProductName,
    ): void {
        Session::put('postnord_service_point_id', $sPointId);
        Session::put('postnord_service_point_name', $sPointName);
        Session::put('postnord_service_point_address', $sPointAddress);
        Session::put('postnord_product_code', $sProductCode);
        Session::put('postnord_product_name', $sProductName);
    }

    /**
     * Recalculate CartProcessor totals for the active PostNord shipping type.
     *
     * @return array<mixed, mixed>|null
     */
    private function recalculateCart(ShippingTypeItem $obShippingTypeItem): ?array
    {
        try {
            /** @var CartProcessor $obCartProcessor */
            $obCartProcessor = CartProcessor::instance();
            $obCartProcessor->setActiveShippingType($obShippingTypeItem);

            return $obCartProcessor->getCartData();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build a map of product_code => display_name from parsed catalog products.
     *
     * @param list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}> $arProducts
     * @return array<string, string>
     */
    private function indexProductDisplayNames(array $arProducts): array
    {
        /** @var array<string, string> $arMap */
        $arMap = [];
        foreach ($arProducts as $arProduct) {
            $arMap[$arProduct['product_code']] = $arProduct['display_name'];
        }

        return $arMap;
    }

    /**
     * Persist server-computed quotes in session when dynamic pricing is active.
     *
     * @param array<string, array{price_default: float, price_net: float, currency: string, product_code: string, product_name?: string, service_point_name?: string, service_point_address?: string}> $arQuotes
     */
    private function persistDynamicQuotes(
        bool $bDynamicPricing,
        ?ShippingTypeItem $obShippingTypeItem,
        string $sPostalCode,
        ?int $iWeightGrams,
        array $arQuotes,
        string $sParcelSig = '',
    ): void {
        if (!$bDynamicPricing || !$obShippingTypeItem instanceof ShippingTypeItem || $arQuotes === []) {
            return;
        }

        $iResolvedTypeId = is_numeric($obShippingTypeItem->id) ? (int) $obShippingTypeItem->id : 0;
        PostNordSessionQuoteStore::storePointQuotes(
            $iResolvedTypeId,
            $sPostalCode,
            $iWeightGrams ?? PostNordPriceCalculator::MIN_WEIGHT_GRAMS,
            $arQuotes,
            $sParcelSig
        );
    }

    /**
     * Return either a sequenced JSON payload (for checkout-postnord.js) or a
     * direct Larajax partial update map.
     *
     * @param list<array<string, mixed>> $arDeliveryOptions
     * @return array<string, mixed>
     */
    private function buildPartialResponse(?int $iRequestSeq, array $arDeliveryOptions, string $sDefaultCity): array
    {
        $sHtml = $this->renderPartial('@service-points');

        if ($iRequestSeq !== null) {
            return [
                'html'         => $sHtml,
                'products'     => $arDeliveryOptions,
                'default_city' => $sDefaultCity,
                'request_seq'  => $iRequestSeq,
            ];
        }

        return ['#postnord-service-points' => $sHtml];
    }

    /**
     * Read and trim a string input field safely.
     */
    private function readTrimmedInput(string $sKey): string
    {
        $mValue = input($sKey);

        return is_string($mValue) ? trim($mValue) : '';
    }

    /**
     * Resolve default postal area / city name from the nearest pickup point.
     *
     * @param list<ServicePoint> $arServicePointList
     */
    private function resolveDefaultCity(array $arServicePointList): string
    {
        $obFirst = $arServicePointList[0] ?? null;

        return $obFirst instanceof ServicePoint ? trim((string) $obFirst->city) : '';
    }

    /**
     * Normalize a user-entered postal code by removing optional country prefix and spaces.
     */
    private function normalizePostalCode(string $sRawPostalCode): string
    {
        $sCleaned = preg_replace('/^(?:NO|SE|DK|FI|LV|LT)[\s-]*/i', '', trim($sRawPostalCode));

        return (string) preg_replace('/\s+/', '', (string) $sCleaned);
    }

    /**
     * Extract ShippingType property array safely.
     *
     * @return array<mixed, mixed>
     */
    private function extractShippingTypeProperty(?ShippingTypeItem $obShippingTypeItem): array
    {
        if (!$obShippingTypeItem instanceof ShippingTypeItem) {
            return [];
        }

        /** @var mixed $mProperty */
        $mProperty = $obShippingTypeItem->property;

        return is_array($mProperty) ? $mProperty : [];
    }
}
