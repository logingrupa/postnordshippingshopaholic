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
        'max_results_comment'      => 'Количество ближайших пунктов выдачи для отображения (1–50).',
        'pickup_provider'          => 'Поставщик пунктов выдачи',
        'pickup_provider_comment'  => 'Выберите плагин перевозчика для обработки выбора пунктов выдачи для этого способа доставки.',
        'tab_pickup'               => 'Пункты выдачи',
        'tab_section_label'        => 'Пункты выдачи PostNord активны',
        'tab_section_comment'      => 'Этот тип доставки использует выбор пунктов выдачи PostNord. Добавьте компонент PostNordLocator в шаблон оформления заказа, чтобы покупатели могли выбрать пункт выдачи.',
        'country_code_comment'     => 'Двухбуквенный ISO-код страны для поиска пунктов выдачи (напр. NO, LV, LT). Переопределяет глобальное значение по умолчанию, если задан.',
        'btn_test_connection'      => 'Проверить соединение',
        'btn_test_connection_hint' => 'Проверяет API-ключ и код страны по известному почтовому индексу без сохранения настроек.',
        'test_connection_no_key'   => 'Введите API-ключ перед проверкой соединения.',
    ],
    'settings' => [
        'label'       => 'PostNord доставка',
        'description' => 'Настройте API-ключ PostNord и параметры пунктов выдачи.',
    ],
];
