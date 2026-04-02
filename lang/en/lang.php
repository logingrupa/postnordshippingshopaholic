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
        'api_key'                  => 'PostNord API Key',
        'api_key_comment'          => 'API key from the PostNord Developer Portal.',
        'country_code'             => 'Country Code',
        'max_results'              => 'Maximum results',
        'max_results_comment'      => 'Number of nearest pickup points to show (1–50).',
        'pickup_provider'          => 'Pickup Provider',
        'pickup_provider_comment'  => 'Select which carrier plugin handles pickup point selection for this shipping method.',
        'tab_pickup'               => 'Pickup Points',
        'tab_section_label'        => 'PostNord Pickup Points Active',
        'tab_section_comment'      => 'This shipping type uses the PostNord service point locator. Add the PostNordLocator component to your checkout template so customers can select a pickup point.',
        'country_code_comment'     => 'Two-letter ISO country code for service point lookup (e.g. NO, LV, LT). Overrides the global default when set.',
        'btn_test_connection'      => 'Test Connection',
        'btn_test_connection_hint' => 'Tests the API key and country code against a known postal code without saving.',
        'test_connection_no_key'   => 'Please enter an API key before testing the connection.',
    ],
    'settings' => [
        'label'       => 'PostNord Shipping',
        'description' => 'Configure PostNord API key and service point settings.',
    ],
];
