<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Models;

use System\Models\SettingModel;

/**
 * Class Settings
 * @package Logingrupa\PostNordShippingShopaholic\Models
 *
 * Backend settings model for PostNord API configuration.
 *
 * @mixin \System\Models\SettingModel
 */
class Settings extends SettingModel
{
    /** @var string */
    public $settingsCode = 'logingrupa_postnordshipping_settings';

    /** @var string */
    public $settingsFields = 'fields.yaml';
}
