<?php

use X2nx\WebmanMcp\Process\Server;
use Mcp\Server as McpServer;

if (!function_exists('mcp_server')) {
    /**
     * Get MCP server instance
     * 
     * @param McpServer|null $server Optional server instance to set
     * @return McpServer
     */
    function mcp_server(?McpServer $server = null): McpServer {
        return Server::instance()->setServer($server)->getServer();
    }
}

if (!function_exists('mcp_server_handle_message')) {
    /**
     * Handle MCP message
     * 
     * @param string $message The MCP message to handle
     * @param string $sessionId Optional session ID
     * @return mixed The response from the MCP server
     */
    function mcp_server_handle_message(string $message = '', string $sessionId = ''): mixed {
        return Server::instance()->handleMessage($message, $sessionId);
    }
}