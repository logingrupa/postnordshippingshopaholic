<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType;

use Event;
use Flash;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Lovata\OrdersShopaholic\Controllers\ShippingTypes;
use Lovata\OrdersShopaholic\Models\ShippingType;

/**
 * Class ExtendShippingTypeFieldsHandler
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType
 *
 * Always injects PostNord-specific fields into the ShippingType backend form,
 * each with a client-side trigger that shows the field only when the api_class
 * dropdown is set to PostNordShippingProcessor.
 *
 * This enables dynamic tab appearance without a page reload: the fields exist in
 * the DOM at all times, and the OctoberCMS trigger mechanism shows/hides them
 * based on the current api_class dropdown value.
 */
class ExtendShippingTypeFieldsHandler
{
    /**
     * Register event listeners
     *
     * @param \Illuminate\Events\Dispatcher $obDispatcher
     */
    public function subscribe($obDispatcher): void
    {
        Event::listen('backend.form.extendFields', function (mixed $obWidget): void {
            if ($obWidget instanceof \Backend\Widgets\Form) {
                $this->extendFields($obWidget);
            }
        });

        $this->extendShippingTypesController();
    }

    /**
     * Extend the ShippingTypes backend controller with the PostNord test
     * connection AJAX handler.
     *
     * The handler reads API key and country code from the unsaved form POST
     * data so the admin can verify credentials before saving.
     */
    protected function extendShippingTypesController(): void
    {
        ShippingTypes::extend(function (ShippingTypes $obController): void {
            $obController->addDynamicMethod(
                'onTestPostNordConnection',
                function (): void {
                    $arProperty  = post('ShippingType[property]', []);
                    $arProperty  = is_array($arProperty) ? $arProperty : [];
                    $mApiKey     = $arProperty['postnord_api_key'] ?? '';
                    $mCountryCode = $arProperty['postnord_country_code'] ?? 'NO';

                    $sApiKey     = is_string($mApiKey) ? trim($mApiKey) : '';
                    $sCountryCode = is_string($mCountryCode) ? trim($mCountryCode) : 'NO';

                    if ($sApiKey === '') {
                        Flash::error(
                            trans('logingrupa.postnordshippingshopaholic::lang.field.test_connection_no_key')
                        );
                        return;
                    }

                    $obClient  = new PostNordClient($sApiKey, $sCountryCode);
                    $arResult  = $obClient->testConnection();

                    if ($arResult['success']) {
                        Flash::success($arResult['message']);
                    } else {
                        Flash::error($arResult['message']);
                    }
                }
            );
        });
    }

    /**
     * Inject PostNord fields into the ShippingType form.
     * Fields are always added regardless of current api_class value.
     * Each field carries a trigger so it is only visible when
     * api_class equals PostNordShippingProcessor::class.
     *
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget): void
    {
        if (!$obWidget->getController() instanceof ShippingTypes) {
            return;
        }

        if ($obWidget->isNested || empty($obWidget->context)) {
            return;
        }

        if (!$obWidget->model instanceof ShippingType) {
            return;
        }

        $obWidget->addTabFields($this->getPostNordFields());
    }

    /**
     * Return field definitions for the PostNord "Pickup Points" tab.
     * Every field has a trigger that hides it unless api_class is set to
     * PostNordShippingProcessor — enabling instant client-side show/hide
     * when the api_class dropdown changes.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getPostNordFields(): array
    {
        $sTriggerCondition = 'value[' . PostNordShippingProcessor::class . ']';

        return [
            'postnord_info' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.tab_section_label',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.tab_section_comment',
                'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'span'    => 'full',
                'type'    => 'section',
                'context' => ['create', 'update', 'preview'],
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'api_class',
                    'condition' => $sTriggerCondition,
                ],
            ],
            'property[postnord_api_key]' => [
                'label'    => 'logingrupa.postnordshippingshopaholic::lang.field.api_key',
                'comment'  => 'logingrupa.postnordshippingshopaholic::lang.field.api_key_comment',
                'tab'      => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'span'     => 'full',
                'type'     => 'text',
                'required' => true,
                'context'  => ['create', 'update'],
                'trigger'  => [
                    'action'    => 'show',
                    'field'     => 'api_class',
                    'condition' => $sTriggerCondition,
                ],
            ],
            'property[postnord_country_code]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.country_code',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.country_code_comment',
                'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'span'    => 'left',
                'type'    => 'dropdown',
                'default' => 'NO',
                'options' => [
                    'NO' => 'Norway',
                    'LV' => 'Latvia',
                    'LT' => 'Lithuania',
                ],
                'context' => ['create', 'update', 'preview'],
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'api_class',
                    'condition' => $sTriggerCondition,
                ],
            ],
            'property[postnord_max_results]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.max_results',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.max_results_comment',
                'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'span'    => 'right',
                'type'    => 'number',
                'default' => 10,
                'context' => ['create', 'update', 'preview'],
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'api_class',
                    'condition' => $sTriggerCondition,
                ],
            ],
            'postnord_test_connection' => [
                'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'type'    => 'partial',
                'path'    => '$/logingrupa/postnordshippingshopaholic/partials/_test_connection',
                'span'    => 'full',
                'context' => ['create', 'update'],
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'api_class',
                    'condition' => $sTriggerCondition,
                ],
            ],
        ];
    }
}
