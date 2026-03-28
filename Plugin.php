<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic;

use Event;
use Logingrupa\PostNordShippingShopaholic\Classes\Event\Order\ExtendOrderModel;
use Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType\ExtendShippingTypeFieldsHandler;
use Logingrupa\PostNordShippingShopaholic\Classes\Event\ShippingType\ExtendShippingTypeModel;
use Logingrupa\PostNordShippingShopaholic\Components\PostNordLocator;
use Logingrupa\PostNordShippingShopaholic\Models\Settings;
use System\Classes\PluginBase;

/**
 * Class Plugin
 * @package Logingrupa\PostNordShippingShopaholic
 * @author Logingrupa
 */
class Plugin extends PluginBase
{
    /**
     * Required plugins
     * @var list<string>
     */
    public $require = [
        'Lovata.Toolbox',
        'Lovata.OrdersShopaholic',
    ];

    /**
     * Returns information about this plugin
     * @return array<string, string>
     */
    #[\Override]
    public function pluginDetails(): array
    {
        return [
            'name'        => 'logingrupa.postnordshippingshopaholic::lang.plugin.name',
            'description' => 'logingrupa.postnordshippingshopaholic::lang.plugin.description',
            'author'      => 'Logingrupa',
            'icon'        => 'icon-map-marker',
        ];
    }

    /**
     * Boot method, called right before the request route
     */
    public function boot(): void
    {
        Event::subscribe(ExtendOrderModel::class);
        Event::subscribe(ExtendShippingTypeModel::class);
        Event::subscribe(ExtendShippingTypeFieldsHandler::class);
    }

    /**
     * Register components
     * @return array<class-string, string>
     */
    #[\Override]
    public function registerComponents(): array
    {
        return [
            PostNordLocator::class => 'PostNordLocator',
        ];
    }

    /**
     * Register backend settings
     * @return array<string, array<string, mixed>>
     */
    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label'       => 'logingrupa.postnordshippingshopaholic::lang.settings.label',
                'description' => 'logingrupa.postnordshippingshopaholic::lang.settings.description',
                'category'    => 'Shopaholic',
                'icon'        => 'icon-map-marker',
                'class'       => Settings::class,
                'order'       => 500,
                'permissions' => [],
            ],
        ];
    }
}
