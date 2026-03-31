<?php

return [
    'plugin' => [
        'name'        => 'PostNord доставка',
        'description' => 'Выбор пунктов выдачи PostNord в оформлении заказа Shopaholic.',
    ],
    'component' => [
        'name'                     => 'PostNord локатор',
        'description'              => 'Отображает выбор пунктов выдачи PostNord с поиском по почтовому индексу.',
        'postal_code_label'        => 'Введите почтовый индекс',
        'postal_code_placeholder'  => 'напр. 1528',
        'no_points'                => 'Введите почтовый индекс для поиска пунктов выдачи.',
        'select_point'             => 'Выберите пункт выдачи',
    ],
    'field' => [
        'api_key'                  => 'API-ключ PostNord',
        'api_key_comment'          => 'API-ключ с портала разработчиков PostNord.',
        'country_code'             => 'Код страны',
        'max_results'              => 'Максимум результатов',
        'pickup_provider'          => 'Поставщик пунктов выдачи',
        'pickup_provider_comment'  => 'Выберите плагин перевозчика для обработки выбора пунктов выдачи для этого способа доставки.',
        'tab_pickup'               => 'Пункты выдачи',
    ],
    'settings' => [
        'label'       => 'PostNord доставка',
        'description' => 'Настройте API-ключ PostNord и параметры пунктов выдачи.',
    ],
];
