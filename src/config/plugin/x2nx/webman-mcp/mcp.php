<?php

return [
    // 服务器配置
    'server' => [
        'name' => 'MCP Server',
        'version' => '1.0.0',
        'description' => 'MCP Server with Multi-Transport Support for Webman',
        'discover' => [
            'base_path' => base_path(),
            'scan_dirs' => [
                'app/mcp',           // 备用扫描目录（大写）
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
        ],
    ],
];