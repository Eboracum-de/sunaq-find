<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'search#run', 'url' => '/search', 'verb' => 'POST'],
        ['name' => 'admin#save', 'url' => '/admin/config', 'verb' => 'POST'],
        ['name' => 'admin#testElastic', 'url' => '/admin/test/elasticsearch', 'verb' => 'POST'],
        ['name' => 'admin#testLlm', 'url' => '/admin/test/llm', 'verb' => 'POST'],
    ],
];
