<?php

namespace app\mcp;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Schema\Content\TextContent;

/**
 * MCP Resources Class
 * 
 * 提供项目相关的资源访问接口，包括文件内容、配置信息、系统状态等
 * 
 * @package app\mcp
 * @author System
 */
class Resources
{
    /**
     * Get user configuration.
     */
    #[McpResource(
        uri: 'config://user/settings',
        mimeType: 'application/json'
    )]
    public function getUserConfig(): array
    {
        return ['theme' => 'dark', 'notifications' => true];
    }

    /**
     * Get user profile by ID.
     */
    #[McpResourceTemplate(
        uriTemplate: 'user://{userId}/profile',
        mimeType: 'application/json'
    )]
    public function getUserProfile(string $userId): array
    {
        return ['id' => $userId, 'name' => 'John Doe'];
    }
}
