<?php

use X2nx\WebmanMcp\Process\Server;
use Workerman\Events\Fiber;
use Workerman\Protocols\Http\Request;
use support\Log;

return [
    'mcp-server' => [
        'handler' => Server::class,
        'listen' => 'http://0.0.0.0:7190',
        'count' => cpu_count(),
        'reloadable' => false,
        'reusePort' => true,
        'eventLoop' => Fiber::class,
        'constructor' => [
            'requestClass'  => Request::class,
            'logger'        => 'log.plugin.x2nx.webman-mcp.mcp',
        ]
    ]
];