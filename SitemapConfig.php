<?php
declare(strict_types=1);
return [
 'host'=>'https://gudok.ru',
 'document_root'=>'/mnt/home/bitrix/ext_www/gudok/public_html',
 'iblocks' => array_merge([49,50,53],range(167,181),[185],range(367,382),[234,305]),
 'candidate_iblocks' => [],
 'batch_size'=>500,
 'max_urls'=>45000,
 'max_bytes'=>45000000,
 'output_relative'=>'/upload/sitemap/xml',
 'log_relative'=>'/upload/sitemap/sitemap.log',
 'lock_relative'=>'/upload/sitemap/sitemap.lock',
 'exclude_path_patterns'=>['~^/bitrix/~i','~^/local/~i','~^/upload/~i'],
];
