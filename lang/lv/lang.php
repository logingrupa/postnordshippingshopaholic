<?php

return [
    'plugin' => [
        'name'        => 'PostNord piegade',
        'description' => 'PostNord izsniegšanas punktu izvēle Shopaholic pasūtījumu noformēšanā.',
    ],
    'component' => [
        'name'                     => 'PostNord meklētājs',
        'description'              => 'Parāda PostNord izsniegšanas punktu izvēli ar pasta indeksa meklēšanu.',
        'postal_code_label'        => 'Ievadiet pasta indeksu',
        'postal_code_placeholder'  => 'piem. 1528',
        'no_points'                => 'Ievadiet pasta indeksu, lai atrastu izsniegšanas punktus.',
        'select_point'             => 'Izvēlieties izsniegšanas punktu',
    ],
    'field' => [
        'api_key'                  => 'PostNord API atslēga',
        'api_key_comment'          => 'API atslēga no PostNord izstrādātāju portāla.',
        'country_code'             => 'Valsts kods',
        'max_results'              => 'Maksimālais rezultātu skaits',
        'pickup_provider'          => 'Pakomātu pakalpojumu sniedzējs',
        'pickup_provider_comment'  => 'Izvēlieties kurjera spraudni, kas apstrādā izsniegšanas punktu izvēli šai piegādes metodei.',
        'tab_pickup'               => 'Izsniegšanas punkti',
    ],
    'settings' => [
        'label'       => 'PostNord piegāde',
        'description' => 'Konfigurējiet PostNord API atslēgu un izsniegšanas punktu iestatījumus.',
    ],
];
