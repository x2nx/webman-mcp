<?php

use X2nx\WebmanMcp\Process\Server;

if (!function_exists('mcp_server_handle_message')) {
    /**
     * handle mcp message
     * @param string $message
     * @param string $sessionId
     * @return mixed
     */
    function mcp_server_handle_message(string $message = '', string $sessionId = ''): mixed {
        return (new Server())->handleMessage($message, $sessionId);
    }
}