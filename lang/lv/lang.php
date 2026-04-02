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
        'max_results_comment'      => 'Tuvāko izsniegšanas punktu skaits, kas tiks parādīts (1–50).',
        'pickup_provider'          => 'Pakomātu pakalpojumu sniedzējs',
        'pickup_provider_comment'  => 'Izvēlieties kurjera spraudni, kas apstrādā izsniegšanas punktu izvēli šai piegādes metodei.',
        'tab_pickup'               => 'Izsniegšanas punkti',
        'tab_section_label'        => 'PostNord izsniegšanas punkti aktīvi',
        'tab_section_comment'      => 'Šis piegādes veids izmanto PostNord izsniegšanas punktu meklētāju. Pievienojiet PostNordLocator komponenti pasūtījuma noformēšanas veidnei, lai klienti varētu izvēlēties izsniegšanas punktu.',
        'country_code_comment'     => 'Divu burtu ISO valsts kods izsniegšanas punktu meklēšanai (piem. NO, LV, LT). Ja iestatīts, aizstāj globālo noklusējumu.',
        'btn_test_connection'      => 'Pārbaudīt savienojumu',
        'btn_test_connection_hint' => 'Pārbauda API atslēgu un valsts kodu ar zināmu pasta indeksu, nesaglabājot iestatījumus.',
        'test_connection_no_key'   => 'Lūdzu, ievadiet API atslēgu pirms savienojuma pārbaudes.',
    ],
    'settings' => [
        'label'       => 'PostNord piegāde',
        'description' => 'Konfigurējiet PostNord API atslēgu un izsniegšanas punktu iestatījumus.',
    ],
];
