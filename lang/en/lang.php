<?php

return [
    'plugin' => [
        'name'        => 'PostNord Shipping',
        'description' => 'PostNord pickup point selection for Shopaholic checkout.',
    ],
    'component' => [
        'name'                     => 'PostNord Locator',
        'description'              => 'Displays PostNord service point selector with postal code lookup.',
        'postal_code_label'        => 'Enter postal code',
        'postal_code_placeholder'  => 'e.g. 1528',
        'no_points'                => 'Enter a postal code to find pickup points.',
        'select_point'             => 'Select pickup point',
    ],
    'field' => [
        'api_key'            => 'PostNord API Key',
        'api_key_comment'    => 'API key from the PostNord Developer Portal.',
        'country_code'       => 'Country Code',
        'max_results'        => 'Maximum results',
        'is_postnord'        => 'Is PostNord Pickup',
        'is_postnord_comment' => 'Enable PostNord pickup point selection for this shipping method.',
        'tab_postnord'       => 'PostNord',
    ],
    'settings' => [
        'label'       => 'PostNord Shipping',
        'description' => 'Configure PostNord API key and service point settings.',
    ],
];
