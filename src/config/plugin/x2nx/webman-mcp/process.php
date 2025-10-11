<?php

use X2nx\WebmanMcp\Process\Server;
use Workerman\Protocols\Http\Request;

return [
    'mcp-server' => [
        'handler'   => Server::class,
        'listen'    => 'http://0.0.0.0:7190',
        'count'     => 1,
        'constructor' => [
            'requestClass'  => Request::class,
        ]
    ]
];