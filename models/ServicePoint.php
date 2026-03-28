<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Models;

use October\Rain\Database\Model;
use October\Rain\Database\Traits\Validation;

/**
 * Class ServicePoint
 * @package Logingrupa\PostNordShippingShopaholic\Models
 *
 * Local cache of PostNord service point data from the V5 API.
 *
 * @property int $id
 * @property string $service_point_id
 * @property string $name
 * @property string $street_name
 * @property string|null $street_number
 * @property string $postal_code
 * @property string $city
 * @property string $country_code
 * @property float|null $northing
 * @property float|null $easting
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ServicePoint extends Model
{
    use Validation;

    /** @var string */
    public $table = 'logingrupa_postnord_service_points';

    /** @var list<string> */
    public $fillable = [
        'service_point_id',
        'name',
        'street_name',
        'street_number',
        'postal_code',
        'city',
        'country_code',
        'northing',
        'easting',
    ];

    /** @var array<string, string> */
    public $rules = [
        'service_point_id' => 'required|string',
        'name'             => 'required|string',
        'postal_code'      => 'required|string',
        'city'             => 'required|string',
        'country_code'     => 'required|string|size:2',
    ];
}
