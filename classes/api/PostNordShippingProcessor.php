<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Interfaces\ShippingPriceProcessorInterface;

/**
 * Class PostNordShippingProcessor
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Registers PostNord as a shipping API class option in the ShippingType backend form.
 * Implements ShippingPriceProcessorInterface so it appears in the api_class dropdown
 * and provides its own field definitions via getFields().
 */
class PostNordShippingProcessor implements ShippingPriceProcessorInterface
{
    private const TAB_PICKUP = 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup';

    protected ShippingTypeItem $obShippingTypeItem;

    public function __construct(ShippingTypeItem $obShippingTypeItem)
    {
        $this->obShippingTypeItem = $obShippingTypeItem;
    }

    /**
     * Return backend form fields to display when this api_class is selected.
     * Values are stored in the ShippingType's `property` JSON column.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getFields(): array
    {
        $arTrigger = [
            'action'    => 'show',
            'field'     => 'api_class',
            'condition' => 'value[' . self::class . ']',
        ];

        return array_merge(
            self::getGeneralFields($arTrigger),
            self::getNorwayFields($arTrigger),
            self::getNordicFields($arTrigger),
        );
    }

    /**
     * PostNord is always valid — pickup point selection happens at checkout,
     * not via shipping type configuration.
     */
    #[\Override]
    public function validate(): bool
    {
        return true;
    }

    /**
     * Return the base price configured on the shipping type.
     */
    #[\Override]
    public function getPrice(): float
    {
        return (float) $this->obShippingTypeItem->price_full;
    }

    /**
     * No API response message for PostNord.
     */
    #[\Override]
    public function getMessage(): string
    {
        return '';
    }

    /**
     * General API mode and environment fields.
     *
     * @param array<string, string> $arTrigger
     * @return array<string, array<string, mixed>>
     */
    private static function getGeneralFields(array $arTrigger): array
    {
        return [
            'postnord_info' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.tab_section_label',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.tab_section_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'full',
                'type'    => 'section',
                'context' => ['create', 'update', 'preview'],
                'trigger' => $arTrigger,
            ],
            'property[postnord_api_mode]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.api_mode',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.api_mode_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'left',
                'type'    => 'dropdown',
                'default' => PostNordClient::MODE_NORWAY,
                'options' => [
                    PostNordClient::MODE_NORWAY => 'PostNord Norway (developer.postnord.no — OAuth2)',
                    PostNordClient::MODE_NORDIC => 'PostNord Nordic (developer.postnord.com — Service Points v5)',
                ],
                'context' => ['create', 'update', 'preview'],
                'trigger' => $arTrigger,
            ],
            'property[postnord_sandbox]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.sandbox',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.sandbox_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'right',
                'type'    => 'switch',
                'default' => false,
                'context' => ['create', 'update', 'preview'],
                'trigger' => $arTrigger,
            ],
        ];
    }

    /**
     * PostNord Norway (developer.postnord.no) OAuth2 fields.
     *
     * @param array<string, string> $arTrigger
     * @return array<string, array<string, mixed>>
     */
    private static function getNorwayFields(array $arTrigger): array
    {
        return [
            'property[postnord_client_id]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.client_id',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.client_id_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'left',
                'type'    => 'text',
                'context' => ['create', 'update'],
                'trigger' => $arTrigger,
            ],
            'property[postnord_client_secret]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.client_secret',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.client_secret_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'right',
                'type'    => 'sensitive',
                'context' => ['create', 'update'],
                'trigger' => $arTrigger,
            ],
            'property[postnord_customer_id]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.customer_id',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.customer_id_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'left',
                'type'    => 'text',
                'context' => ['create', 'update', 'preview'],
                'trigger' => $arTrigger,
            ],
            'property[postnord_sender_postal_code]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.sender_postal_code',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.sender_postal_code_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'right',
                'type'    => 'text',
                'default' => '1528',
                'context' => ['create', 'update', 'preview'],
                'trigger' => $arTrigger,
            ],
        ];
    }

    /**
     * PostNord Nordic (developer.postnord.com) Service Points v5 and shared limit fields.
     *
     * @param array<string, string> $arTrigger
     * @return array<string, array<string, mixed>>
     */
    private static function getNordicFields(array $arTrigger): array
    {
        return [
            'property[postnord_api_key]' => [
                'label'    => 'logingrupa.postnordshippingshopaholic::lang.field.api_key',
                'comment'  => 'logingrupa.postnordshippingshopaholic::lang.field.api_key_comment',
                'tab'      => self::TAB_PICKUP,
                'span'     => 'full',
                'type'     => 'sensitive',
                'required' => false,
                'context'  => ['create', 'update'],
                'trigger'  => $arTrigger,
            ],
            'property[postnord_country_code]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.country_code',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.country_code_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'left',
                'type'    => 'dropdown',
                'default' => 'NO',
                'options' => [
                    'NO' => 'Norway',
                    'SE' => 'Sweden',
                    'DK' => 'Denmark',
                    'FI' => 'Finland',
                    'LV' => 'Latvia',
                    'LT' => 'Lithuania',
                ],
                'context' => ['create', 'update', 'preview'],
                'trigger' => $arTrigger,
            ],
            'property[postnord_max_results]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.max_results',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.max_results_comment',
                'tab'     => self::TAB_PICKUP,
                'span'    => 'right',
                'type'    => 'number',
                'default' => 10,
                'context' => ['create', 'update', 'preview'],
                'trigger' => $arTrigger,
            ],
        ];
    }
}
