<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\Session;
use Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint\ServicePointListStore;
use Logingrupa\PostNordShippingShopaholic\Models\ServicePoint;

/**
 * Class PostNordLocator
 * @package Logingrupa\PostNordShippingShopaholic\Components
 *
 * AJAX component for PostNord service point selection.
 * Provides postal code input and service point radio buttons via Larajax.
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
     * AJAX handler: fetch service points for a postal code.
     *
     * Expects POST data: postal_code (4-5 digits), optional shipping_type_id,
     * optional request_seq (when sent, returns {html, request_seq} so checkout JS
     * can discard stale out-of-order responses without double-rendering).
     *
     * @return array<string, mixed>
     */
    public function onGetServicePoints(): array
    {
        $mPostalCode = input('postal_code');
        $sPostalCode = $this->normalizePostalCode(is_string($mPostalCode) ? $mPostalCode : '');
        $mRequestSeq = input('request_seq');
        $iRequestSeq = is_numeric($mRequestSeq) ? (int) $mRequestSeq : null;

        if (!preg_match('/^\d{4,5}$/', $sPostalCode)) {
            $this->page['arServicePointList'] = [];
            $this->page['bHasSearched'] = false;

            return $this->buildPartialResponse($iRequestSeq);
        }

        $mShippingTypeId = input('shipping_type_id');
        $iShippingTypeId = is_numeric($mShippingTypeId) ? (int) $mShippingTypeId : null;

        /** @var ServicePointListStore $obStore */
        $obStore = ServicePointListStore::instance();
        $arEntryList = $obStore->getSearchResults($sPostalCode, $iShippingTypeId);

        $this->page['arServicePointList'] = $this->loadOrderedServicePoints($arEntryList);
        $this->page['bHasSearched'] = true;

        return $this->buildPartialResponse($iRequestSeq);
    }

    /**
     * AJAX handler: save selected service point to session.
     *
     * @return array<string, string>
     */
    public function onSelectServicePoint(): array
    {
        $mPointId = input('service_point_id');
        $mPointName = input('service_point_name');
        $mPointAddress = input('service_point_address');

        $sPointId = is_string($mPointId) ? trim($mPointId) : '';
        $sPointName = is_string($mPointName) ? trim($mPointName) : '';
        $sPointAddress = is_string($mPointAddress) ? trim($mPointAddress) : '';

        Session::put('postnord_service_point_id', $sPointId);
        Session::put('postnord_service_point_name', $sPointName);
        Session::put('postnord_service_point_address', $sPointAddress);

        return ['result' => 'ok'];
    }

    /**
     * Return either a sequenced JSON payload (for checkout-postnord.js stale-response
     * filtering) or a direct Larajax partial update map.
     *
     * @return array<string, mixed>
     */
    private function buildPartialResponse(?int $iRequestSeq): array
    {
        $sHtml = $this->renderPartial('@service-points');

        if ($iRequestSeq !== null) {
            return [
                'html'        => $sHtml,
                'request_seq' => $iRequestSeq,
            ];
        }

        return ['#postnord-service-points' => $sHtml];
    }

    /**
     * Normalize a user-entered postal code by removing optional country prefix and spaces.
     */
    private function normalizePostalCode(string $sRawPostalCode): string
    {
        $sCleaned = preg_replace('/^(?:NO|SE|DK|FI|LV|LT)[\s-]*/i', '', trim($sRawPostalCode));
        $sCleaned = preg_replace('/\s+/', '', is_string($sCleaned) ? $sCleaned : '');

        return is_string($sCleaned) ? $sCleaned : '';
    }

    /**
     * Load ServicePoint models in a single query, preserving distance order and
     * applying each search's relative distance_in_meters in memory.
     *
     * @param list<array{id: int, distance_in_meters: int|null}> $arEntryList
     * @return list<ServicePoint>
     */
    private function loadOrderedServicePoints(array $arEntryList): array
    {
        if ($arEntryList === []) {
            return [];
        }

        $arIdList = array_map(static fn (array $arEntry): int => $arEntry['id'], $arEntryList);
        $obPointCollection = ServicePoint::whereIn('id', $arIdList)->get()->keyBy('id');

        /** @var list<ServicePoint> $arServicePointList */
        $arServicePointList = [];

        foreach ($arEntryList as $arEntry) {
            $obServicePoint = $obPointCollection->get($arEntry['id']);
            if ($obServicePoint instanceof ServicePoint) {
                $obServicePoint->distance_in_meters = $arEntry['distance_in_meters'];
                $arServicePointList[] = $obServicePoint;
            }
        }

        return $arServicePointList;
    }
}
