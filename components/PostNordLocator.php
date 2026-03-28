<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShipping\Components;

use Cms\Classes\ComponentBase;

/**
 * Class PostNordLocator
 * @package Logingrupa\PostNordShipping\Components
 *
 * AJAX component for PostNord service point selection.
 * Provides postal code input and service point radio buttons via Larajax.
 */
class PostNordLocator extends ComponentBase
{
    /**
     * Returns information about this component
     * @return array<string, string>
     */
    #[\Override]
    public function componentDetails(): array
    {
        return [
            'name'        => 'logingrupa.postnordshipping::lang.component.name',
            'description' => 'logingrupa.postnordshipping::lang.component.description',
        ];
    }
}
