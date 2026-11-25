<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Logingrupa\PostNordShippingShopaholic\Classes\Store\ServicePoint\ServicePointListStore;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Interfaces\ShippingPriceProcessorInterface;

/**
 * Class PostNordShippingProcessor
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Registers PostNord as a shipping API class option in the ShippingType backend form.
 * Implements ShippingPriceProcessorInterface so it appears in the api_class dropdown,
 * provides its own field definitions via getFields(), and optionally computes live
 * weight/distance-based shipping rates from PostNord Norway's B2C Products API.
 */
class PostNordShippingProcessor implements ShippingPriceProcessorInterface
{
    private const TAB_PICKUP = 'logingrupa.postnordshippingshopaholic::lang.field.tab_pickup';

    protected ShippingTypeItem $obShippingTypeItem;
    protected string $sValidationError = '';

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
            self::getNorwayPricingFields($arTrigger),
            self::getNorwayPricingRuleFields($arTrigger),
            self::getNorwayPackagingFields($arTrigger),
            self::getNorwayEnvelopeFields($arTrigger),
            self::getNordicFields($arTrigger),
        );
    }

    /**
     * Validate that a dynamic-pricing PostNord order has an active server quote
     * matching the current cart and submitted delivery product/pickup point/postal code.
     * Flat-rate and Nordic v5 modes always return true.
     */
    #[\Override]
    public function validate(): bool
    {
        $this->sValidationError = '';

        $arProperty = $this->extractProperty();
        if (!PostNordPriceCalculator::isDynamicPricingEnabled($arProperty)) {
            return true;
        }

        $iShippingTypeId = $this->resolveShippingTypeId();
        $arQuote = $iShippingTypeId > 0 ? PostNordSessionQuoteStore::getActiveSelectedQuote($iShippingTypeId) : null;
        if ($arQuote === null || !PostNordOrderValidator::matchesSubmittedCheckoutFields($arQuote)) {
            $this->sValidationError = PostNordOrderValidator::getExpiredQuoteMessage();

            return false;
        }

        return true;
    }

    /**
     * Return either the server-quoted dynamic PostNord Norway price (when
     * dynamic pricing is enabled and a valid delivery option is selected) or
     * the flat fallback price configured on the ShippingType.
     * Invalidates the active quote when a cart change makes the chosen option unavailable.
     */
    #[\Override]
    public function getPrice(): float
    {
        $fFallbackPrice = (float) $this->obShippingTypeItem->price_full;
        $arProperty = $this->extractProperty();
        $iShippingTypeId = $this->resolveShippingTypeId();

        if (!PostNordPriceCalculator::isDynamicPricingEnabled($arProperty) || $iShippingTypeId <= 0) {
            return $fFallbackPrice;
        }

        $arQuote = PostNordSessionQuoteStore::getActiveSelectedQuote($iShippingTypeId);
        if ($arQuote === null) {
            return $fFallbackPrice;
        }

        $arParcel = PostNordParcelCalculator::calculateCurrentParcel($arProperty);
        $sCurrentSig = PostNordParcelCalculator::buildParcelSignature($arParcel);
        $bParcelMatches = $arQuote['parcel_sig'] !== ''
            ? $arQuote['parcel_sig'] === $sCurrentSig
            : $arQuote['weight_grams'] === $arParcel['weight'];

        if ($bParcelMatches || $arQuote['postal_code'] === '') {
            return $arQuote['price_default'];
        }

        $fRefreshedPrice = $this->refreshQuoteForParcel($iShippingTypeId, $arQuote, $arParcel, $arProperty);
        if ($fRefreshedPrice !== null) {
            return $fRefreshedPrice;
        }

        PostNordSessionQuoteStore::clearSelectedQuote($iShippingTypeId);

        return $fFallbackPrice;
    }

    /**
     * Return the last PostNord validation error message, if any.
     */
    #[\Override]
    public function getMessage(): string
    {
        return $this->sValidationError;
    }

    /**
     * Extract ShippingType property array safely.
     *
     * @return array<mixed, mixed>
     */
    private function extractProperty(): array
    {
        /** @var mixed $mProperty */
        $mProperty = $this->obShippingTypeItem->property;

        return is_array($mProperty) ? $mProperty : [];
    }

    /**
     * Extract positive integer ShippingType ID safely.
     */
    private function resolveShippingTypeId(): int
    {
        /** @var mixed $mTypeId */
        $mTypeId = $this->obShippingTypeItem->id;

        return is_numeric($mTypeId) ? (int) $mTypeId : 0;
    }

    /**
     * Re-quote the selected pickup point or home delivery option when cart
     * weight or dimensions changed after selection.
     *
     * @param array{shipping_type_id: int, service_point_id: string, postal_code: string, weight_grams: int, parcel_sig: string, price_default: float, price_net: float, currency: string, product_code: string, product_name?: string, service_point_name?: string, service_point_address?: string} $arQuote
     * @param array{weight: int, length: int|null, width: int|null, height: int|null, cart_total_nok: float|null, parcel_letter_max_price: float|null, packaging: string} $arParcel
     * @param array<mixed, mixed> $arProperty
     */
    private function refreshQuoteForParcel(
        int $iShippingTypeId,
        array $arQuote,
        array $arParcel,
        array $arProperty,
    ): ?float {
        /** @var ServicePointListStore $obStore */
        $obStore = ServicePointListStore::instance();
        $arCatalog = $obStore->getDeliveryCatalog($arQuote['postal_code'], $iShippingTypeId, $arParcel['weight'], $arParcel);
        if ($arCatalog['points'] === [] && $arCatalog['products'] === []) {
            return null;
        }

        $arBuiltProducts = PostNordCatalogPresenter::buildDeliveryOptionList($arCatalog['products'], true, $arProperty);
        $arProductNames = [];
        foreach ($arCatalog['products'] as $arProduct) {
            $arProductNames[$arProduct['product_code']] = $arProduct['display_name'];
        }
        $arLoadedPoints = PostNordCatalogPresenter::loadOrderedServicePoints($arCatalog['points'], true, $arProperty, $arProductNames);
        $arQuoteMap = $arBuiltProducts['quotes'] + $arLoadedPoints['quotes'];
        if ($arQuoteMap === []) {
            return null;
        }

        $sParcelSig = PostNordParcelCalculator::buildParcelSignature($arParcel);
        PostNordSessionQuoteStore::storePointQuotes($iShippingTypeId, $arQuote['postal_code'], $arParcel['weight'], $arQuoteMap, $sParcelSig);
        $arUpdated = PostNordSessionQuoteStore::selectPointQuote($iShippingTypeId, $arQuote['service_point_id'], $arQuote['postal_code']);

        return $arUpdated !== null ? $arUpdated['price_default'] : null;
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
                'type'    => 'radio',
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
        $arDependsOn = ['property[postnord_api_mode]'];

        return [
            'property[postnord_client_id]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.client_id',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.client_id_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'text',
                'context'   => ['create', 'update'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_client_secret]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.client_secret',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.client_secret_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'sensitive',
                'context'   => ['create', 'update'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_customer_id]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.customer_id',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.customer_id_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'text',
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_sender_postal_code]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.sender_postal_code',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.sender_postal_code_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'text',
                'default'   => '1528',
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
        ];
    }

    /**
     * PostNord Norway live weight-based dynamic pricing fields.
     *
     * @param array<string, string> $arTrigger
     * @return array<string, array<string, mixed>>
     */
    private static function getNorwayPricingFields(array $arTrigger): array
    {
        $arDependsOn = ['property[postnord_api_mode]'];

        return [
            'property[postnord_dynamic_pricing]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.dynamic_pricing',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.dynamic_pricing_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'switch',
                'default'   => false,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_enabled_products]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.enabled_products',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.enabled_products_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'full',
                'type'      => 'checkboxlist',
                'default'   => PostNordPriceCalculator::DEFAULT_ENABLED_CODES,
                'options'   => [
                    PostNordPriceCalculator::CODE_LOCKER        => 'logingrupa.postnordshippingshopaholic::lang.field.product_code_s',
                    PostNordPriceCalculator::CODE_SERVICE_POINT => 'logingrupa.postnordshippingshopaholic::lang.field.product_code_z',
                    PostNordPriceCalculator::CODE_HOME_SMALL    => 'logingrupa.postnordshippingshopaholic::lang.field.product_code_b',
                    PostNordPriceCalculator::CODE_HOME          => 'logingrupa.postnordshippingshopaholic::lang.field.product_code_h',
                    PostNordPriceCalculator::CODE_HOME_ATTENDED => 'logingrupa.postnordshippingshopaholic::lang.field.product_code_q',
                ],
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_product_filter]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.product_filter',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.product_filter_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'dropdown',
                'default'   => PostNordPriceCalculator::PRODUCT_ALL,
                'options'   => [
                    PostNordPriceCalculator::PRODUCT_ALL           => 'logingrupa.postnordshippingshopaholic::lang.field.product_filter_all',
                    PostNordPriceCalculator::PRODUCT_LOCKER        => 'logingrupa.postnordshippingshopaholic::lang.field.product_filter_locker',
                    PostNordPriceCalculator::PRODUCT_SERVICE_POINT => 'logingrupa.postnordshippingshopaholic::lang.field.product_filter_service_point',
                ],
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
        ];
    }

    /**
     * PostNord Norway fallback weight, VAT, markup, and rounding rule fields.
     *
     * @param array<string, string> $arTrigger
     * @return array<string, array<string, mixed>>
     */
    private static function getNorwayPricingRuleFields(array $arTrigger): array
    {
        $arDependsOn = ['property[postnord_api_mode]'];

        return [
            'property[postnord_default_weight_grams]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.default_weight_grams',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.default_weight_grams_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'number',
                'default'   => PostNordPriceCalculator::DEFAULT_ITEM_WEIGHT_GRAMS,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_vat_percent]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.vat_percent',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.vat_percent_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'number',
                'default'   => PostNordPriceCalculator::DEFAULT_VAT_PERCENT,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_price_markup]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.price_markup',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.price_markup_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'number',
                'default'   => 0,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_price_rounding]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.price_rounding',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.price_rounding_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'dropdown',
                'default'   => PostNordPriceCalculator::ROUND_CEIL,
                'options'   => [
                    PostNordPriceCalculator::ROUND_CEIL    => 'logingrupa.postnordshippingshopaholic::lang.field.rounding_ceil',
                    PostNordPriceCalculator::ROUND_CEIL_5  => 'logingrupa.postnordshippingshopaholic::lang.field.rounding_ceil_5',
                    PostNordPriceCalculator::ROUND_CEIL_10 => 'logingrupa.postnordshippingshopaholic::lang.field.rounding_ceil_10',
                    PostNordPriceCalculator::ROUND_NONE    => 'logingrupa.postnordshippingshopaholic::lang.field.rounding_none',
                ],
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
        ];
    }

    /**
     * PostNord Norway default shipping box dimensions & packaging tare weight
     * (follows Lovata.Shopaholic Measure settings for unit labels & values).
     *
     * @param array<string, string> $arTrigger
     * @return array<string, array<string, mixed>>
     */
    private static function getNorwayPackagingFields(array $arTrigger): array
    {
        $arDependsOn = ['property[postnord_api_mode]'];

        return [
            'property[postnord_packaging_weight]' => [
                'label'     => PostNordMeasureHelper::formatWeightLabel('logingrupa.postnordshippingshopaholic::lang.field.packaging_weight'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.packaging_weight_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'number',
                'default'   => 0,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_box_length]' => [
                'label'     => PostNordMeasureHelper::formatDimensionLabel('logingrupa.postnordshippingshopaholic::lang.field.box_length'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.box_length_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'number',
                'default'   => 0,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_box_width]' => [
                'label'     => PostNordMeasureHelper::formatDimensionLabel('logingrupa.postnordshippingshopaholic::lang.field.box_width'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.box_width_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'number',
                'default'   => 0,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_box_height]' => [
                'label'     => PostNordMeasureHelper::formatDimensionLabel('logingrupa.postnordshippingshopaholic::lang.field.box_height'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.box_height_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'number',
                'default'   => 0,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
        ];
    }

    /**
     * PostNord Norway bubble-wrap envelope & Home Small (mailbox) limit fields.
     *
     * @param array<string, string> $arTrigger
     * @return array<string, array<string, mixed>>
     */
    private static function getNorwayEnvelopeFields(array $arTrigger): array
    {
        $arDependsOn = ['property[postnord_api_mode]'];

        return [
            'property[postnord_envelope_enabled]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.envelope_enabled',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.envelope_enabled_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'switch',
                'default'   => false,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_envelope_max_weight]' => [
                'label'     => PostNordMeasureHelper::formatWeightLabel('logingrupa.postnordshippingshopaholic::lang.field.envelope_max_weight'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.envelope_max_weight_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'number',
                'default'   => PostNordMeasureHelper::fromGrams(PostNordParcelCalculator::DEFAULT_ENVELOPE_MAX_WEIGHT_GRAMS),
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_envelope_length]' => [
                'label'     => PostNordMeasureHelper::formatDimensionLabel('logingrupa.postnordshippingshopaholic::lang.field.envelope_length'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.envelope_length_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'number',
                'default'   => PostNordMeasureHelper::fromCentimeters(PostNordParcelCalculator::DEFAULT_ENVELOPE_LENGTH_CM),
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_envelope_width]' => [
                'label'     => PostNordMeasureHelper::formatDimensionLabel('logingrupa.postnordshippingshopaholic::lang.field.envelope_width'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.envelope_width_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'number',
                'default'   => PostNordMeasureHelper::fromCentimeters(PostNordParcelCalculator::DEFAULT_ENVELOPE_WIDTH_CM),
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_envelope_height]' => [
                'label'     => PostNordMeasureHelper::formatDimensionLabel('logingrupa.postnordshippingshopaholic::lang.field.envelope_height'),
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.envelope_height_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'number',
                'default'   => PostNordMeasureHelper::fromCentimeters(PostNordParcelCalculator::DEFAULT_ENVELOPE_HEIGHT_CM),
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_parcel_letter_max_price]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.parcel_letter_max_price',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.parcel_letter_max_price_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'right',
                'type'      => 'number',
                'default'   => 0,
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
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
        $arDependsOn = ['property[postnord_api_mode]'];

        return [
            'property[postnord_api_key]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.api_key',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.api_key_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'full',
                'type'      => 'sensitive',
                'required'  => false,
                'context'   => ['create', 'update'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
            ],
            'property[postnord_country_code]' => [
                'label'     => 'logingrupa.postnordshippingshopaholic::lang.field.country_code',
                'comment'   => 'logingrupa.postnordshippingshopaholic::lang.field.country_code_comment',
                'tab'       => self::TAB_PICKUP,
                'span'      => 'left',
                'type'      => 'dropdown',
                'default'   => 'NO',
                'options'   => [
                    'NO' => 'Norway',
                    'SE' => 'Sweden',
                    'DK' => 'Denmark',
                    'FI' => 'Finland',
                    'LV' => 'Latvia',
                    'LT' => 'Lithuania',
                ],
                'context'   => ['create', 'update', 'preview'],
                'dependsOn' => $arDependsOn,
                'trigger'   => $arTrigger,
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
