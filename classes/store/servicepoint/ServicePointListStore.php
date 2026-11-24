<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Models\ShippingType;
use Lovata\Toolbox\Classes\Store\AbstractStoreWithParam;

/**
 * Class ServicePointListStore
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint
 *
 * Provides cached PostNord pickup point lookups keyed by shipping type,
 * API mode, sandbox flag, country code, limit, and searched postal code
 * with a bounded TTL. Keeps per-search distances in the cache payload so
 * neighboring searches do not overwrite each other's distances on shared rows.
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
     * Return distance-ordered service point entries for a searched postal code.
     *
     * Cache keys are isolated by shipping type, mode, sandbox flag, country,
     * max results, and postal code with a 6-hour TTL. Degraded DB fallback
     * results (when the API fails) are never written to cache.
     *
     * @return list<array{id: int, distance_in_meters: int|null}>
     */
    public function getSearchResults(string $sPostalCode, ?int $iShippingTypeId = null): array
    {
        $sCleanPostalCode = trim($sPostalCode);
        if ($sCleanPostalCode === '') {
            return [];
        }

        $obShippingTypeItem = $this->resolvePostNordShippingTypeItem($iShippingTypeId ?? $this->iShippingTypeId);
        if ($obShippingTypeItem === null) {
            return [];
        }

        $arScope = ServicePointSearchScope::fromShippingType($obShippingTypeItem, $sCleanPostalCode);
        $sCacheKey = ServicePointSearchScope::buildCacheKey($arScope);

        $mCached = Cache::get($sCacheKey);
        if (is_array($mCached)) {
            return $this->normalizeCachedEntries($mCached);
        }

        $arApiEntries = $this->fetchAndPersistFromApi($obShippingTypeItem, $sCleanPostalCode, $arScope);
        if ($arApiEntries !== []) {
            Cache::put($sCacheKey, $arApiEntries, self::SEARCH_CACHE_TTL_SECONDS);

            return $arApiEntries;
        }

        return $this->queryFallbackEntries($sCleanPostalCode, $arScope['country_code']);
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
     * Fetch nearest service points from PostNord API, upsert valid rows, and return ordered entries.
     *
     * @param array{shipping_type_id: int, api_mode: string, sandbox: string, country_code: string, max_results: int, postal_code: string} $arScope
     * @return list<array{id: int, distance_in_meters: int|null}>
     */
    private function fetchAndPersistFromApi(
        ShippingTypeItem $obShippingTypeItem,
        string $sPostalCode,
        array $arScope,
    ): array {
        $arServicePointList = PostNordClient::fromShippingType($obShippingTypeItem)
            ->findNearestByAddress($sPostalCode, $arScope['max_results']);

        /** @var list<array{id: int, distance_in_meters: int|null}> $arEntries */
        $arEntries = [];

        foreach ($arServicePointList as $arServicePointData) {
            $arEntry = $this->persistServicePoint($arServicePointData, $arScope['country_code']);
            if ($arEntry !== null) {
                $arEntries[] = $arEntry;
            }
        }

        return $arEntries;
    }

    /**
     * Validate and upsert a single service point, skipping malformed entries
     * so one invalid point cannot abort the entire lookup.
     *
     * @param array<string, mixed> $arServicePointData
     * @return array{id: int, distance_in_meters: int|null}|null
     */
    private function persistServicePoint(array $arServicePointData, string $sFallbackCountry): ?array
    {
        $arNormalized = ServicePointSearchScope::normalizePointAttributes($arServicePointData, $sFallbackCountry);
        if ($arNormalized === null) {
            return null;
        }

        $mDistance = $arServicePointData['distance_in_meters'] ?? null;
        $iDistance = is_numeric($mDistance) ? (int) $mDistance : null;

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
        if (!is_numeric($mModelId)) {
            return null;
        }

        return [
            'id'                 => (int) $mModelId,
            'distance_in_meters' => $iDistance,
        ];
    }

    /**
     * Country-scoped DB fallback when the upstream API is unavailable.
     *
     * @return list<array{id: int, distance_in_meters: int|null}>
     */
    private function queryFallbackEntries(string $sPostalCode, string $sCountryCode): array
    {
        $arRawIdList = ServicePoint::where('postal_code', $sPostalCode)
            ->where('country_code', $sCountryCode)
            ->pluck('id')
            ->all();

        /** @var list<array{id: int, distance_in_meters: int|null}> $arEntries */
        $arEntries = [];
        foreach ($arRawIdList as $mId) {
            if (is_numeric($mId)) {
                $arEntries[] = [
                    'id'                 => (int) $mId,
                    'distance_in_meters' => null,
                ];
            }
        }

        return $arEntries;
    }

    /**
     * Normalize cached entry arrays defensively.
     *
     * @param array<mixed, mixed> $arCached
     * @return list<array{id: int, distance_in_meters: int|null}>
     */
    private function normalizeCachedEntries(array $arCached): array
    {
        /** @var list<array{id: int, distance_in_meters: int|null}> $arEntries */
        $arEntries = [];

        foreach ($arCached as $mEntry) {
            if (!is_array($mEntry) || !is_numeric($mEntry['id'] ?? null)) {
                continue;
            }

            $mDistance = $mEntry['distance_in_meters'] ?? null;
            $arEntries[] = [
                'id'                 => (int) $mEntry['id'],
                'distance_in_meters' => is_numeric($mDistance) ? (int) $mDistance : null,
            ];
        }

        return $arEntries;
    }

    /**
     * Resolve the active PostNord ShippingType item.
     */
    private function resolvePostNordShippingTypeItem(?int $iShippingTypeId): ?ShippingTypeItem
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
}
