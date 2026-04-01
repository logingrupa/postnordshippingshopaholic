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
 *
 * PostNord does not calculate shipping price dynamically — the base price configured
 * on the shipping type's Settings tab is used. Pickup point selection is handled
 * at checkout by the PostNordLocator component.
 */
class PostNordShippingProcessor implements ShippingPriceProcessorInterface
{
    protected ShippingTypeItem $obShippingTypeItem;

    public function __construct(ShippingTypeItem $obShippingTypeItem)
    {
        $this->obShippingTypeItem = $obShippingTypeItem;
    }

    /**
     * Return backend form fields to display when this api_class is selected.
     * The upstream ExtendShippingTypeFieldsHandler calls $sApiClass::getFields()
     * and renders these under the "Pickup Points" tab on the ShippingType form.
     * Values are stored in the ShippingType's `property` JSON column.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getFields(): array
    {
        return [
            'postnord_info' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.tab_section_label',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.tab_section_comment',
                'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'span'    => 'full',
                'type'    => 'section',
                'context' => ['create', 'update', 'preview'],
            ],
            'property[postnord_api_key]' => [
                'label'    => 'logingrupa.postnordshippingshopaholic::lang.field.api_key',
                'comment'  => 'logingrupa.postnordshippingshopaholic::lang.field.api_key_comment',
                'tab'      => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'span'     => 'full',
                'type'     => 'text',
                'required' => true,
                'context'  => ['create', 'update'],
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
            ],
            'property[postnord_max_results]' => [
                'label'   => 'logingrupa.postnordshippingshopaholic::lang.field.max_results',
                'comment' => 'logingrupa.postnordshippingshopaholic::lang.field.max_results_comment',
                'tab'     => 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup',
                'span'    => 'right',
                'type'    => 'number',
                'default' => 10,
                'context' => ['create', 'update', 'preview'],
            ],
        ];
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
     * PostNord does not use a live rate API — the store configures a flat
     * shipping price in the standard price field on the Settings tab.
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
}
