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
        'api_key'                  => 'PostNord API-nøkkel',
        'api_key_comment'          => 'API-nøkkel fra PostNord utviklerportalen.',
        'country_code'             => 'Landskode',
        'max_results'              => 'Maksimalt antall resultater',
        'max_results_comment'      => 'Antall nærmeste hentepunkter som skal vises (1–50).',
        'pickup_provider'          => 'Hentepunktleverandør',
        'pickup_provider_comment'  => 'Velg transportør-plugin som håndterer hentepunktvalg for denne fraktmetoden.',
        'tab_pickup'               => 'Hentepunkter',
        'tab_section_label'        => 'PostNord hentepunkter er aktive',
        'tab_section_comment'      => 'Denne frakttypen bruker PostNord hentepunktvelger. Legg til PostNordLocator-komponenten i kassemalen slik at kunder kan velge et hentepunkt.',
        'country_code_comment'     => 'To-bokstavers ISO-landskode for hentepunktsøk (f.eks. NO, LV, LT). Overstyrer den globale standarden når den er angitt.',
    ],
    'settings' => [
        'label'       => 'PostNord frakt',
        'description' => 'Konfigurer PostNord API-nøkkel og hentepunktinnstillinger.',
    ],
];
