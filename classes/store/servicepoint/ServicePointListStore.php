<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint;

use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;
use Lovata\Toolbox\Classes\Store\AbstractStoreWithParam;

/**
 * Class ServicePointListStore
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint
 *
 * Extends AbstractStoreWithParam to provide cached ID lists of service points
 * keyed by postal code. When a postal code is not found in the local DB,
 * the store auto-fetches from the PostNord API and caches the results.
 */
class ServicePointListStore extends AbstractStoreWithParam
{
    /** @var static|null */
    protected static $instance;

    /**
     * Get service point ID list from database for a postal code.
     * If no records exist locally, fetch from the PostNord API and persist.
     *
     * @return list<int>
     */
    #[\Override]
    protected function getIDListFromDB(): array
    {
        $sPostalCode = is_string($this->sValue) ? $this->sValue : '';

        $arIdList = $this->queryIdListByPostalCode($sPostalCode);

        if ($arIdList !== []) {
            return $arIdList;
        }

        $this->fetchAndPersistFromApi($sPostalCode);

        return $this->queryIdListByPostalCode($sPostalCode);
    }

    /**
     * Query local DB for service point IDs by postal code
     *
     * @return list<int>
     */
    private function queryIdListByPostalCode(string $sPostalCode): array
    {
        $arRawIdList = ServicePoint::where('postal_code', $sPostalCode)->pluck('id')->all();

        /** @var list<int> $arIdList */
        $arIdList = array_values(array_map(
            static fn (mixed $mValue): int => is_int($mValue) ? $mValue : (int) (is_numeric($mValue) ? $mValue : 0),
            $arRawIdList
        ));

        return $arIdList;
    }

    /**
     * Fetch service points from PostNord API and save to local DB
     */
    private function fetchAndPersistFromApi(string $sPostalCode): void
    {
        $arServicePointList = PostNordClient::fromSettings()
            ->findNearestByAddress($sPostalCode);

        foreach ($arServicePointList as $arServicePointData) {
            ServicePoint::updateOrCreate(
                ['service_point_id' => $arServicePointData['service_point_id']],
                $arServicePointData
            );
        }
    }
}
