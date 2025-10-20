<?php
namespace x2nx\WebmanMcp\Process;

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Connection\TcpConnection;
use Mcp\Server as McpServer;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use X2nx\WebmanMcp\Cache\Webman as WebmanCache;
use X2nx\WebmanMcp\Transport\SseHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use support\Response;
use support\Log;

class Server
{
    /**
     * @var Worker
     */
    protected Worker $worker;
    
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var Psr17Factory
     */
    protected Psr17Factory $psr17Factory;

    /**
     * 全局共享的Session Store
     * 确保所有Server实例共享同一个session store
     */
    protected $globalSessionStore;

    /**
     * 配置前缀
     */
    private const CONFIG_PREFIX = 'plugin.x2nx.webman-mcp.%s';

    /**
     * Worker 启动
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->logger = Log::channel(sprintf(self::CONFIG_PREFIX, 'mcp'));
        $this->worker = $worker;
        $this->psr17Factory = new Psr17Factory();
        // 创建全局共享的Session Store（基于 webman Cache）
        $sessionTtl = (int) config(sprintf(self::CONFIG_PREFIX, 'mcp.session.ttl'), 3600);
        $sessionStoreName = (string) config(sprintf(self::CONFIG_PREFIX, 'mcp.session.store'), '');
        try {
            $this->globalSessionStore = new WebmanCache($sessionStoreName, $sessionTtl);
            $this->logger->info('Using Webman CacheSessionStore for MCP sessions', [
                'store' => $sessionStoreName ?: '(default)',
                'ttl' => $sessionTtl
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to init CacheSessionStore, fallback to InMemorySessionStore', [
                'error' => $e->getMessage()
            ]);
            $this->globalSessionStore = new FileSessionStore(runtime_path('sessions'));
        }
    }

    /**
     * Worker 重新加载
     */
    public function onWorkerReload(): void
    {
        $this->logger->info('MCP Process Worker reloaded');
    }

    /**
     * Worker 停止
     */
    public function onWorkerStop(): void
    {
        $this->logger->info('MCP Process Worker stopped');
    }

    /**
     * 连接建立
     */
    public function onConnect(TcpConnection $connection): void
    {
        // 连接建立，无需特殊处理
    }

    /**
     * 消息处理
     */
    public function onMessage(TcpConnection $connection, Request $request): void
    {
        $path = $request->path();
        
        try {
            // 根据路径路由到对应的处理器
            $response = match ($path) {
                '/sse', '/message' => $this->handleSseMessage($connection, $request),
                '/mcp' => $this->handleMcpMessage($connection, $request),
                default => $this->handleError($path),
            };
            if (!empty($response) && is_array($response)) {
                $this->sendResponse($connection, $response);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Request handling error', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendResponse($connection, [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'error' => 'Internal Server Error',
                    'message' => 'An error occurred while processing your request'
                ])
            ]);
        }
    }

    /**
     * 错误处理
     */
    public function onError(TcpConnection $connection, int $code, string $msg): void
    {
        $this->logger->error('Connection error', [
            'connection_id' => $connection->id,
            'code' => $code,
            'message' => $msg
        ]);
    }

    /**
     * 连接关闭
     */
    public function onClose(TcpConnection $connection): void
    {
        // 连接关闭，无需特殊处理
        $this->logger->info('Connection closed', [
            'connection_id' => $connection->id
        ]);
    }

    /**
     * 处理 SSE 端点（GET /sse）
     */
    private function handleSseMessage(TcpConnection $connection, Request $request): mixed
    {
        try {
            $server = $this->createMcpServer();
            
            $transport = new SseHttpTransport(
                connection: $connection,
                request: $this->createPsrRequest($request),
                logger: $this->logger
            );
            
            return $server->run($transport);
            
        } catch (\Throwable $e) {
            $this->logger->error('SSE request handling error', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 发送错误响应
            $this->sendResponse($connection, [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'error' => 'Internal Server Error',
                    'message' => 'Failed to establish SSE connection'
                ])
            ]);
            
            return null;
        }
    }

    /**
     * handle mcp endpoint
     */
    private function handleMcpMessage(TcpConnection $connection, Request $request): array
    {
        try {
            $server = $this->createMcpServer();

            $transport = new StreamableHttpTransport(
                request: $this->createPsrRequest($request),
                responseFactory: $this->psr17Factory,
                streamFactory: $this->psr17Factory,
                logger: $this->logger
            );
            
            $response = $server->run($transport);
            
            // 转换 PSR-7 响应为 Webman 响应格式
            return [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => (string)$response->getBody()
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error('MCP stream request handling error', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'error' => 'Internal Server Error',
                    'message' => 'Failed to process MCP request'
                ])
            ];
        }
    }

    /**
     * handle error
     */
    private function handleError(string $path): array
    {
        return [
            'status' => 404,
            'headers' => [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*'
            ],
            'body' => json_encode([
                'error' => 'Not Found',
                'message' => 'Not Found',
            ])
        ];
    }
 
    /**
     * create psr request from workerman request
     */
    private function createPsrRequest(?Request $workermanRequest = null): ServerRequestInterface
    {
        if ($workermanRequest) {
            // 从 Workerman Request 创建 PSR-7 Request
            $uri = $this->psr17Factory->createUri($workermanRequest->uri());
            $body = $this->psr17Factory->createStream($workermanRequest->rawBody());
            
            $psrRequest = $this->psr17Factory->createServerRequest(
                $workermanRequest->method(),
                $uri,
                $_SERVER
            );
            
            // 设置请求体
            $psrRequest = $psrRequest->withBody($body);
            
            // 设置请求头
            foreach ($workermanRequest->header() as $name => $value) {
                $psrRequest = $psrRequest->withHeader($name, $value);
            }
            
            // 设置查询参数
            $psrRequest = $psrRequest->withQueryParams($workermanRequest->get());
            
            return $psrRequest;
        }
        
        // 回退到全局变量
        $creator = new ServerRequestCreator(
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory
        );
        
        return $creator->fromGlobals();
    }

    /**
     * create mcp server instance
     */
    private function createMcpServer(): McpServer
    {
        return McpServer::builder()
            ->setServerInfo(
                config(sprintf(self::CONFIG_PREFIX, 'mcp.server.name')),
                config(sprintf(self::CONFIG_PREFIX, 'mcp.server.version')),
                config(sprintf(self::CONFIG_PREFIX, 'mcp.server.description'))
            )
            ->setDiscovery(
                config(sprintf(self::CONFIG_PREFIX, 'mcp.server.discover.base_path'), base_path()),
                config(sprintf(self::CONFIG_PREFIX, 'mcp.server.discover.scan_dirs'), ['app/mcp']),
                config(sprintf(self::CONFIG_PREFIX, 'mcp.server.discover.exclude_dirs'), [])
            )
            ->setSession($this->globalSessionStore)
            ->setLogger($this->logger)
            ->build();
    }

    /**
     * start stdio server
     */
    public function stdioServer(): void
    {
        // 初始化必要的组件
        $this->logger = Log::channel(sprintf(self::CONFIG_PREFIX, 'mcp'));
        $this->globalSessionStore = new FileSessionStore(runtime_path('sessions'));
        $server = $this->createMcpServer();
        $transport = new StdioTransport(logger: $this->logger);
        $this->logger->info('MCP Server started in stdio mode');
        $server->run($transport);
    }

    /**
     * send response to client
     */
    private function sendResponse(TcpConnection $connection, array $response): void
    {
        $connection->send(new Response(
            $response['status'],
            $response['headers'] ?? [],
            $response['body'] ?? ''
        ));
    }
}