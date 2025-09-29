<?php
return [
    'server' => [
        'handler' => X2nx\WebmanMcp\Process\Server::class,
        'listen' => 'http://0.0.0.0:7190',
        'count' => cpu_count(),
        'reloadable' => false,
        'reusePort' => true,
        'eventLoop' => Workerman\Events\Fiber::class,
        'constructor' => [
            'requestClass' => Workerman\Protocols\Http\Request::class,
            'logger' => support\Log::channel('mcp'),
        ]
    ]
];