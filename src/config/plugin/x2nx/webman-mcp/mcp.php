<?php

use Mcp\Schema\Enum\ProtocolVersion;

return [
    // server configuration
    'server' => [
        'name' => 'MCP Server',
        'version' => '1.0.0',
        'description' => 'MCP Server with Multi-Transport Support for Webman',
        'protocol_version' => ProtocolVersion::V2025_06_18,
        'pagination' => 50,
        'instructions' => '',
        'capabilities' => [],
        'discover' => [
            'base_path' => base_path(),
            'scan_dirs' => [
                'app/mcp',
            ],
            'exclude_dirs' => [
                'vendor',
                'runtime',
                'database',
                'docker',
                'public',
                'config',
                'support',
            ],
            'cache' => [
                // enable discovery cache
                'enable' => false,
                // cache expiration time (seconds), default 1 hour
                'ttl' => 3600,
                // cache store name, leave empty to use default cache
                'store' => '',
            ],
        ],
        'transport' => [
            'sse' => [
                // enable sse transport
                'enable' => true,
                // sse transport route
                'route' => [
                    '/sse', 
                    '/message'
                ],
            ],
            'stream' => [
                // enable stream transport
                'enable' => true,
                // stream transport route
                'route' => [
                    '/mcp',
                ],
            ],
        ],
        // session cache configuration (based on webman cache)
        'session' => [
            // session expiration time (seconds), default 1 hour
            'ttl' => 3600,
            // cache store name, leave empty to use default cache
            'store' => '',
        ]
    ]
];