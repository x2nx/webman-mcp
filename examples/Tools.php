<?php

namespace app\mcp;

use Mcp\Capability\Attribute\McpTool;

/**
 * MCP工具类 - 提供基本的系统信息和状态查询功能
 */
class Tools
{
    /**
     * 获取PHP信息
     */
    #[McpTool(
        name: 'get_php_info',
        description: '获取PHP基本信息，包括PHP版本、内存使用、运行时间等'
    )]
    public function getPhpInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'phpinfo' => phpinfo(),
        ];
    }
    
}
