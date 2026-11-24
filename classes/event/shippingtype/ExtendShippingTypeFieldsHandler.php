<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType;

use Backend\Classes\FormField;
use Event;
use Flash;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordClient;
use Logingrupa\PostNordShippingShopaholic\Classes\Api\PostNordShippingProcessor;
use Lovata\OrdersShopaholic\Controllers\ShippingTypes;
use Lovata\OrdersShopaholic\Models\ShippingType;
use October\Rain\Element\ElementHolder;

/**
 * Class ExtendShippingTypeFieldsHandler
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType
 *
 * Injects PostNord-specific fields into the ShippingType backend form and
 * toggles Norway OAuth2 vs Nordic API Key fields dynamically based on the
 * selected PostNord API Mode.
 */
class ExtendShippingTypeFieldsHandler
{
    private const SENSITIVE_PLACEHOLDER = '__hidden__';

    /** @var list<string> */
    private const NORWAY_FIELD_LIST = [
        'property[postnord_client_id]',
        'property[postnord_client_secret]',
        'property[postnord_customer_id]',
        'property[postnord_sender_postal_code]',
    ];

    /** @var list<string> */
    private const NORDIC_FIELD_LIST = [
        'property[postnord_api_key]',
        'property[postnord_country_code]',
    ];

    /**
     * Register event listeners.
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

        $this->extendShippingTypeFilterFields();
        $this->extendShippingTypesController();
    }

    /**
     * Filter Norway vs Nordic fields on ShippingType form init and dependsOn refresh.
     */
    protected function extendShippingTypeFilterFields(): void
    {
        ShippingType::extend(function (ShippingType $obModel): void {
            $obModel->bindEvent(
                'model.form.filterFields',
                function (mixed $obFormWidget, mixed $obFields): void {
                    if ($obFields instanceof ElementHolder) {
                        $this->filterModeFields($obFields);
                    }
                }
            );
        });
    }

    /**
     * Hide Nordic-only fields when Norway OAuth2 mode is active, and vice versa.
     */
    protected function filterModeFields(ElementHolder $obFields): void
    {
        $obModeField = $obFields->get('property[postnord_api_mode]');
        if (!$obModeField instanceof FormField) {
            return;
        }

        $mModeValue = $obModeField->get('value');
        $sMode = is_string($mModeValue) && trim($mModeValue) !== ''
            ? trim($mModeValue)
            : PostNordClient::MODE_NORWAY;

        $bIsNordic = $sMode === PostNordClient::MODE_NORDIC;

        $this->setFieldsHidden($obFields, self::NORWAY_FIELD_LIST, $bIsNordic);
        $this->setFieldsHidden($obFields, self::NORDIC_FIELD_LIST, !$bIsNordic);
    }

    /**
     * Extend the ShippingTypes backend controller with the PostNord test
     * connection AJAX handler.
     */
    protected function extendShippingTypesController(): void
    {
        ShippingTypes::extend(function (ShippingTypes $obController): void {
            $obController->addDynamicMethod(
                'onTestPostNordConnection',
                function (mixed $mRecordId = null): void {
                    $arProperty = post('ShippingType[property]', []);
                    $arProperty = is_array($arProperty) ? $arProperty : [];
                    $arProperty = $this->resolveSensitivePlaceholders($arProperty, $mRecordId);

                    $obClient = PostNordClient::fromConfig($arProperty);
                    $arResult = $obClient->testConnection();

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
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getPostNordFields(): array
    {
        $arFields = PostNordShippingProcessor::getFields();

        $arFields['postnord_test_connection'] = [
            'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
            'type'    => 'partial',
            'path'    => '$/logingrupa/postnordshippingshopaholic/partials/_test_connection',
            'span'    => 'full',
            'context' => ['create', 'update'],
            'trigger' => [
                'action'    => 'show',
                'field'     => 'api_class',
                'condition' => 'value[' . PostNordShippingProcessor::class . ']',
            ],
        ];

        return $arFields;
    }

    /**
     * Toggle hidden flag on a group of form fields.
     *
     * @param list<string> $arFieldNameList
     */
    private function setFieldsHidden(ElementHolder $obFields, array $arFieldNameList, bool $bHidden): void
    {
        foreach ($arFieldNameList as $sFieldName) {
            $obField = $obFields->get($sFieldName);
            if ($obField instanceof FormField) {
                $obField->hidden($bHidden);
            }
        }
    }

    /**
     * Replace untouched `__hidden__` sensitive widget placeholders with values
     * from the existing ShippingType record.
     *
     * @param array<mixed, mixed> $arProperty
     * @return array<mixed, mixed>
     */
    private function resolveSensitivePlaceholders(array $arProperty, mixed $mRecordId): array
    {
        if (!is_numeric($mRecordId)) {
            return $arProperty;
        }

        $obRecord = ShippingType::find((int) $mRecordId);
        if (!$obRecord instanceof ShippingType) {
            return $arProperty;
        }

        /** @var mixed $mSavedProperty */
        $mSavedProperty = $obRecord->property;
        if (!is_array($mSavedProperty)) {
            return $arProperty;
        }

        foreach (['postnord_client_secret', 'postnord_api_key'] as $sSecretKey) {
            if (($arProperty[$sSecretKey] ?? null) === self::SENSITIVE_PLACEHOLDER) {
                $arProperty[$sSecretKey] = $mSavedProperty[$sSecretKey] ?? '';
            }
        }

        return $arProperty;
    }
}
