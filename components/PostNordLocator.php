<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShipping\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\Session;
use Logingrupa\PostNordShipping\Classes\Store\ServicePoint\ServicePointListStore;
use Logingrupa\PostNordShipping\Models\ServicePoint;

/**
 * Class PostNordLocator
 * @package Logingrupa\PostNordShipping\Components
 *
 * AJAX component for PostNord service point selection.
 * Provides postal code input and service point radio buttons via Larajax.
 *
 * Usage in Twig:
 *   {% component 'PostNordLocator' %}
 */
class PostNordLocator extends ComponentBase
{
    /**
     * Returns information about this component
     * @return array<string, string>
     */
    #[\Override]
    public function componentDetails(): array
    {
        return [
            'name'        => 'logingrupa.postnordshipping::lang.component.name',
            'description' => 'logingrupa.postnordshipping::lang.component.description',
        ];
    }

    /**
     * AJAX handler: fetch service points for a postal code.
     *
     * Expects POST data: postal_code (string, 4-5 digits)
     * Returns partial update for #postnord-service-points container.
     *
     * @return array<string, string>
     */
    public function onGetServicePoints(): array
    {
        $mPostalCode = input('postal_code');
        $sPostalCode = is_string($mPostalCode) ? trim($mPostalCode) : '';

        if (!preg_match('/^\d{4,5}$/', $sPostalCode)) {
            $this->page['arServicePointList'] = [];

            return ['#postnord-service-points' => $this->renderPartial('@service-points')];
        }

        /** @var list<int> $arIdList */
        $arIdList = ServicePointListStore::instance()->get($sPostalCode);

        $arServicePointList = [];
        foreach ($arIdList as $iServicePointId) {
            $obServicePoint = ServicePoint::find($iServicePointId);
            if ($obServicePoint instanceof ServicePoint) {
                $arServicePointList[] = $obServicePoint;
            }
        }

        $this->page['arServicePointList'] = $arServicePointList;

        return ['#postnord-service-points' => $this->renderPartial('@service-points')];
    }

    /**
     * AJAX handler: save selected service point to session.
     *
     * Expects POST data: service_point_id, service_point_name, service_point_address
     *
     * @return array<string, string>
     */
    public function onSelectServicePoint(): array
    {
        $mPointId = input('service_point_id');
        $mPointName = input('service_point_name');
        $mPointAddress = input('service_point_address');

        $sPointId = is_string($mPointId) ? $mPointId : '';
        $sPointName = is_string($mPointName) ? $mPointName : '';
        $sPointAddress = is_string($mPointAddress) ? $mPointAddress : '';

        Session::put('postnord_service_point_id', $sPointId);
        Session::put('postnord_service_point_name', $sPointName);
        Session::put('postnord_service_point_address', $sPointAddress);

        return ['result' => 'ok'];
    }
}
