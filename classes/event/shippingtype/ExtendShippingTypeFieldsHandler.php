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
 */
class ExtendShippingTypeFieldsHandler
{
    private const SENSITIVE_PLACEHOLDER = '__hidden__';

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

        $this->extendShippingTypesController();
    }

    /**
     * Extend the ShippingTypes backend controller with the PostNord test
     * connection AJAX handler.
     *
     * Reads credentials from unsaved form POST data so the admin can verify
     * Norway OAuth2 or Nordic API key credentials before saving. Unmodified
     * `type: sensitive` fields submit `__hidden__`, which is resolved from the
     * saved ShippingType record.
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
