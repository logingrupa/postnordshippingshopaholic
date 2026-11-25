<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordPriceCalculator;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Models\ShippingType;
use Lovata\Toolbox\Classes\Store\AbstractStoreWithParam;

/**
 * Class ServicePointListStore
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint
 *
 * Provides cached PostNord delivery catalog & pickup point lookups keyed by
 * shipping type, API mode, sandbox flag, country code, limit, searched postal
 * code, parcel weight, and enabled products.
 */
class ServicePointListStore extends AbstractStoreWithParam
{
    private const SEARCH_CACHE_TTL_SECONDS = 21600;

    /** @var static|null */
    protected static $instance;

    /** @var int|null Optional active ShippingType ID for credential resolution */
    private ?int $iShippingTypeId = null;

    /**
     * Set an optional ShippingType ID to prefer when resolving API credentials.
     */
    public function setShippingTypeId(?int $iShippingTypeId): self
    {
        $this->iShippingTypeId = $iShippingTypeId;

        return $this;
    }

    /**
     * Return both selectable delivery options (Norway mode) and distance-ordered
     * pickup point entries for a searched postal code in a single cached lookup.
     *
     * @param array{weight: int, length: int|null, width: int|null, height: int|null, cart_total_nok?: float|null, parcel_letter_max_price?: float|null, packaging?: string}|null $arParcel
     * @return array{products: list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}>, points: list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}>}
     */
    public function getDeliveryCatalog(
        string $sPostalCode,
        ?int $iShippingTypeId = null,
        ?int $iWeightGrams = null,
        ?array $arParcel = null,
    ): array {
        $arEmpty = ['products' => [], 'points' => []];
        $sCleanPostalCode = trim($sPostalCode);
        if ($sCleanPostalCode === '') {
            return $arEmpty;
        }

        $obShippingTypeItem = $this->resolvePostNordShippingTypeItem($iShippingTypeId ?? $this->iShippingTypeId);
        if ($obShippingTypeItem === null) {
            return $arEmpty;
        }

        $arScope = ServicePointSearchScope::fromShippingType($obShippingTypeItem, $sCleanPostalCode, $iWeightGrams, $arParcel);
        $sCacheKey = ServicePointSearchScope::buildCacheKey($arScope);

        $mCached = Cache::get($sCacheKey);
        if (is_array($mCached)) {
            return ServicePointCacheNormalizer::normalizeCachedCatalog($mCached);
        }

        $arApiCatalog = $this->fetchAndPersistCatalogFromApi($obShippingTypeItem, $sCleanPostalCode, $arScope, $arParcel);
        if ($arApiCatalog['points'] !== [] || $arApiCatalog['products'] !== []) {
            Cache::put($sCacheKey, $arApiCatalog, self::SEARCH_CACHE_TTL_SECONDS);

            return $arApiCatalog;
        }

        return [
            'products' => [],
            'points'   => $this->queryFallbackEntries($sCleanPostalCode, $arScope['country_code']),
        ];
    }

    /**
     * Return distance-ordered service point entries for a searched postal code.
     *
     * @return list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}>
     */
    public function getSearchResults(
        string $sPostalCode,
        ?int $iShippingTypeId = null,
        ?int $iWeightGrams = null,
    ): array {
        return $this->getDeliveryCatalog($sPostalCode, $iShippingTypeId, $iWeightGrams)['points'];
    }

    /**
     * Resolve the active PostNord ShippingType item.
     */
    public function resolvePostNordShippingTypeItem(?int $iShippingTypeId): ?ShippingTypeItem
    {
        $obShippingType = null;

        if ($iShippingTypeId !== null && $iShippingTypeId > 0) {
            $obShippingType = ShippingType::where('id', $iShippingTypeId)
                ->where('api_class', PostNordShippingProcessor::class)
                ->first();
        }

        if (!$obShippingType instanceof ShippingType) {
            $obShippingType = ShippingType::where('api_class', PostNordShippingProcessor::class)->first();
        }

        if (!$obShippingType instanceof ShippingType) {
            return null;
        }

        $obItem = ShippingTypeItem::make($obShippingType->id);

        return $obItem->isEmpty() ? null : $obItem;
    }

    /**
     * Legacy AbstractStoreWithParam entry point returning persisted IDs.
     *
     * @return list<int>
     */
    #[\Override]
    protected function getIDListFromDB(): array
    {
        $sPostalCode = is_string($this->sValue) ? trim($this->sValue) : '';
        if ($sPostalCode === '') {
            return [];
        }

        $arEntries = $this->getSearchResults($sPostalCode, $this->iShippingTypeId);

        return array_map(
            static fn (array $arEntry): int => $arEntry['id'],
            $arEntries
        );
    }

    /**
     * Fetch delivery products and nearest pickup points from PostNord API and persist points.
     *
     * @param array{shipping_type_id: int, api_mode: string, sandbox: string, country_code: string, max_results: int, postal_code: string, weight_grams: int, product_filter: string, parcel_sig: string} $arScope
     * @param array{weight: int, length: int|null, width: int|null, height: int|null, cart_total_nok?: float|null, parcel_letter_max_price?: float|null, packaging?: string}|null $arParcel
     * @return array{products: list<array{product_code: string, display_name: string, description: string, tip: string, price_net: float|null, price_currency: string, working_days: int|null, needs_pickup_point: bool}>, points: list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}>}
     */
    private function fetchAndPersistCatalogFromApi(
        ShippingTypeItem $obShippingTypeItem,
        string $sPostalCode,
        array $arScope,
        ?array $arParcel = null,
    ): array {
        /** @var mixed $mProperty */
        $mProperty = $obShippingTypeItem->property;
        $arProperty = is_array($mProperty) ? $mProperty : [];

        $iWeightArg = $arScope['weight_grams'] > 0 ? $arScope['weight_grams'] : null;
        $bIsNorway = $arScope['api_mode'] === PostNordClient::MODE_NORWAY;
        $arApiProducts = $bIsNorway ? PostNordPriceCalculator::resolveProductFilterList($arProperty) : [];
        $arAllowedCodes = $bIsNorway ? PostNordPriceCalculator::resolveEnabledProductCodes($arProperty) : [];

        $arCatalog = PostNordClient::fromShippingType($obShippingTypeItem)
            ->findDeliveryCatalog($sPostalCode, $arScope['max_results'], $iWeightArg, $arApiProducts, $arAllowedCodes, $bIsNorway ? $arParcel : null);

        /** @var list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}> $arEntries */
        $arEntries = [];
        foreach ($arCatalog['points'] as $arServicePointData) {
            $arEntry = $this->persistServicePoint($arServicePointData, $arScope['country_code']);
            if ($arEntry !== null) {
                $arEntries[] = $arEntry;
            }
        }

        return [
            'products' => $arCatalog['products'],
            'points'   => $arEntries,
        ];
    }

    /**
     * Validate and upsert a single service point, skipping malformed entries.
     *
     * @param array<string, mixed> $arServicePointData
     * @return array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}|null
     */
    private function persistServicePoint(array $arServicePointData, string $sFallbackCountry): ?array
    {
        $arNormalized = ServicePointSearchScope::normalizePointAttributes($arServicePointData, $sFallbackCountry);
        if ($arNormalized === null) {
            return null;
        }

        try {
            $obModel = ServicePoint::updateOrCreate(
                [
                    'service_point_id' => $arNormalized['service_point_id'],
                    'country_code'     => $arNormalized['country_code'],
                ],
                $arNormalized
            );
        } catch (\Throwable $obException) {
            Log::warning('Skipped invalid PostNord service point during persistence', [
                'service_point_id' => $arNormalized['service_point_id'],
                'message'          => $obException->getMessage(),
            ]);

            return null;
        }

        $mModelId = $obModel->getKey();

        return is_numeric($mModelId)
            ? ServicePointCacheNormalizer::buildPointCacheEntry((int) $mModelId, $arServicePointData)
            : null;
    }

    /**
     * Country-scoped DB fallback when the upstream API is unavailable.
     *
     * @return list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}>
     */
    private function queryFallbackEntries(string $sPostalCode, string $sCountryCode): array
    {
        $arRawIdList = ServicePoint::where('postal_code', $sPostalCode)
            ->where('country_code', $sCountryCode)
            ->pluck('id')
            ->all();

        /** @var list<array{id: int, distance_in_meters: int|null, price_net: float|null, price_currency: string, product_code: string}> $arEntries */
        $arEntries = [];
        foreach ($arRawIdList as $mId) {
            if (is_numeric($mId)) {
                $arEntries[] = ServicePointCacheNormalizer::buildPointCacheEntry((int) $mId, []);
            }
        }

        return $arEntries;
    }
}
