<?php
declare(strict_types=1);

return [
    // Заполните ID инфоблоков: '10,20,30' или '10-15,20,25-30'.
    // Также можно указать PHP-массив: [10, 20, 30].
    'root_iblocks' => '',

    // null — взять домен из настроек сайта Битрикс (SERVER_NAME), схема HTTPS.
    // При необходимости укажите явно, например 'https://example.ru'.
    'host' => null,
    'site_id' => 's1',
    // null — автоматически найти корень сайта по расположению конфига.
    'document_root' => null,

    // Все опубликованные XML пишутся непосредственно в корень сайта.
    'index_name' => 'sitemap_index.xml',
    'file_prefix' => 'sitemap-auto',
    'work_relative' => '/upload/sitemap-runtime',
    'batch_size' => 500,
    'sort_chunk_size' => 10000,
    'max_urls' => 45000,
    'max_bytes' => 45000000,
    'retention_days' => 7,
    'allow_empty' => false,
    // false — все активные элементы, в том числе архивные, без фильтра дат.
    'active_date' => false,
    'check_permissions' => true,
    'exclude_path_patterns' => ['~^/(?:bitrix|local|upload)(?:/|$)~i'],
];
