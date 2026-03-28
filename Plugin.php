<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShipping;

use Event;
use Logingrupa\PostNordShipping\Classes\Event\Order\ExtendOrderModel;
use Logingrupa\PostNordShipping\Classes\Event\ShippingType\ExtendShippingTypeFieldsHandler;
use Logingrupa\PostNordShipping\Classes\Event\ShippingType\ExtendShippingTypeModel;
use Logingrupa\PostNordShipping\Components\PostNordLocator;
use Logingrupa\PostNordShipping\Models\Settings;
use System\Classes\PluginBase;

/**
 * Class Plugin
 * @package Logingrupa\PostNordShipping
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
            'name'        => 'logingrupa.postnordshipping::lang.plugin.name',
            'description' => 'logingrupa.postnordshipping::lang.plugin.description',
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
                'label'       => 'logingrupa.postnordshipping::lang.settings.label',
                'description' => 'logingrupa.postnordshipping::lang.settings.description',
                'category'    => 'Shopaholic',
                'icon'        => 'icon-map-marker',
                'class'       => Settings::class,
                'order'       => 500,
                'permissions' => [],
            ],
        ];
    }
}
