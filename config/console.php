<?php
return [
    'id'                    => 'Dumper',
    'controllerNamespace'   => 'app\controllers',
    'basePath'              => dirname(__DIR__),
    'params' => [
        'basePath'              => dirname(__DIR__) . '/dump',
        'pagesPath'             => '/pages',
        'docsPath'              => '/data',
        'imgPath'               => '/img',
        'cssPath'               => '/css',
        'jsPath'                => '/js',
        'externalLinksPath'     => '/sites',
        'timezone'              => 'Asia/Vladivostok',
        'useragent'             => 'Dumper',
        'regBlackList'          => '(instagram|whatsapp|appdv)'
    ]
];