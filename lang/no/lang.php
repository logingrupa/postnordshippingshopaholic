<?php

return [
    'plugin' => [
        'name'        => 'PostNord frakt',
        'description' => 'PostNord hentepunkt-valg i Shopaholic kassen.',
    ],
    'component' => [
        'name'                     => 'PostNord-lokator',
        'description'              => 'Viser PostNord hentepunkt-velger med postnummersøk.',
        'postal_code_label'        => 'Skriv inn postnummer',
        'postal_code_placeholder'  => 'f.eks. 1528',
        'no_points'                => 'Skriv inn postnummer for å finne hentepunkter.',
        'select_point'             => 'Velg hentepunkt',
    ],
    'field' => [
        'api_key'            => 'PostNord API-nøkkel',
        'api_key_comment'    => 'API-nøkkel fra PostNord utviklerportalen.',
        'country_code'       => 'Landskode',
        'max_results'        => 'Maksimalt antall resultater',
        'is_postnord'        => 'Er PostNord hentepunkt',
        'is_postnord_comment' => 'Aktiver PostNord hentepunkt-valg for denne fraktmetoden.',
        'tab_postnord'       => 'PostNord',
    ],
    'settings' => [
        'label'       => 'PostNord frakt',
        'description' => 'Konfigurer PostNord API-nøkkel og hentepunktinnstillinger.',
    ],
];
