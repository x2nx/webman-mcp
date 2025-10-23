<?php
namespace X2nx\WebmanMcp\Process;

use Webman\Channel\Client;
use Workerman\Worker;
use Workerman\Protocols\Http\ServerSentEvents;
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
     * global shared session store
     * ensure all Server instances share the same session store
     */
    protected $globalSessionStore;
    /**
     * mcp sse connects
     */
    protected static array $mcpSseConnects = [];
    /**
     * config prefix
     */
    private const CONFIG_PREFIX = 'plugin.x2nx.webman-mcp.%s';
    /**
     * Worker started
     * 
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->startChannelClient($worker);
        try {
            $this->logger = Log::channel(sprintf(self::CONFIG_PREFIX, 'mcp'));
            $this->worker = $worker;
            $this->psr17Factory = new Psr17Factory();
            
            // create global shared session store (based on webman Cache)
            $sessionTtl = (int) config(sprintf(self::CONFIG_PREFIX, 'mcp.session.ttl'), 3600);
            $sessionStoreName = (string) config(sprintf(self::CONFIG_PREFIX, 'mcp.session.store'), '');
            
            try {
                $this->globalSessionStore = WebmanCache::forSessions($sessionStoreName, $sessionTtl);
                $this->logger->info('Using Webman CacheSessionStore for MCP sessions', [
                    'store' => $sessionStoreName ?: '(default)',
                    'ttl' => $sessionTtl
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to init CacheSessionStore, fallback to FileSessionStore', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->globalSessionStore = new FileSessionStore(runtime_path('sessions'));
            }
            
            $this->logger->info('MCP Process Worker started successfully', [
                'worker_id' => $worker->id,
                'session_store' => get_class($this->globalSessionStore)
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to start MCP Process Worker', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Worker reloaded
     * 
     * @return void
     */
    public function onWorkerReload(): void
    {
        $this->logger->info('MCP Process Worker reloaded');
    }

    /**
     * Worker stopped
     * 
     * @return void
     */
    public function onWorkerStop(): void
    {
        $this->logger->info('MCP Process Worker stopped');
        self::$mcpSseConnects = [];
    }

    /**
     * connection established
     * 
     * @param TcpConnection $connection
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        // connection established, no special handling
    }

    /**
     * 消息处理
     */
    public function onMessage(TcpConnection $connection, Request $request): void
    {
        $path = $request->path();

        $transports = $this->getConfig('mcp.server.transport', []);

        $response = $this->handleError($path);
        
        try {
            if ($transports['sse']['enable'] && in_array($path, $transports['sse']['route'])) {
                $response = $this->handleSseMessage($connection, $request);
            }
            if ($transports['stream']['enable'] && in_array($path, $transports['stream']['route'])) {
                $response = $this->handleMcpMessage($connection, $request);
            }
            if (!empty($response) && is_array($response)) {
                $this->sendResponse($connection, $response);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Request handling error', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->handleError($path);
        }
    }

    /**
     * error handling
     * 
     * @param TcpConnection $connection
     * @param int $code
     * @param string $message
     * @return void
     */
    public function onError(TcpConnection $connection, int $code, string $message): void
    {
        $this->logger->error('Connection error', [
            'connection_id' => $connection->id,
            'code' => $code,
            'message' => $message
        ]);
        $this->sendResponse(
            $connection, 
            $this->handleError(null, $code, $message)
        );
    }

    /**
     * connection closed
     * 
     * @param TcpConnection $connection
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        // clear sse connection info
        foreach (self::$mcpSseConnects as $sessionId => $connectionInfo) {
            if ($connectionInfo['connection_id'] === $connection->id && $connectionInfo['worker_id'] === $this->worker->id) {
                unset(self::$mcpSseConnects[$sessionId]);
                break;
            }
        }
    }

    /**
     * handle sse message
     * @param TcpConnection $connection
     * @param Request $request
     * @return mixed
     */
    private function handleSseMessage(TcpConnection $connection, Request $request): mixed
    {
        try {
            $server = $this->createMcpServer();
            
            $transport = new SseHttpTransport(
                connection: $connection,
                request: $this->createPsrRequest($request),
                cache: $this->globalSessionStore,
                logger: $this->logger
            );
            
            return $server->run($transport);
            
        } catch (\Throwable $e) {
            $this->logger->error('SSE request handling error', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // error response
            $this->sendResponse(
                $connection, 
                $this->handleError(null, 500, 'Failed to establish SSE connection')
            );
            return null;
        }
    }

    /**
     * handle mcp endpoint
     * @param TcpConnection $connection
     * @param Request $request
     * @return array
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
            return $this->handleError(null, 500, 'Failed to process MCP request');
        }
    }

    /**
     * handle error
     * @param string|null $path
     * @param int $status
     * @param string $message
     * @return array
     */
    private function handleError(?string $path = null, int $status = 404, string $message = 'The service is currently unavailable'): array
    {
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*'
            ],
            'body' => json_encode([
                'error' => $status === 404 ? 'Not Found' : 'Error',
                'message' => $message,
            ])
        ];
    }
 
    /**
     * create psr request from workerman request
     * 
     * @param Request|null $workermanRequest
     * @return ServerRequestInterface
     */
    private function createPsrRequest(?Request $workermanRequest = null): ServerRequestInterface
    {
        if ($workermanRequest) {
            $uri = $this->psr17Factory->createUri($workermanRequest->uri());
            $body = $this->psr17Factory->createStream($workermanRequest->rawBody());
            $psrRequest = $this->psr17Factory->createServerRequest(
                $workermanRequest->method(),
                $uri,
                $_SERVER
            );
            $psrRequest = $psrRequest->withBody($body);
            foreach ($workermanRequest->header() as $name => $value) {
                $psrRequest = $psrRequest->withHeader($name, $value);
            }
            $psrRequest = $psrRequest->withQueryParams($workermanRequest->get());
            return $psrRequest;
        }
        // create psr request from globals
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
     * 
     * @return McpServer
     */
    private function createMcpServer(): McpServer
    {
        $builder = McpServer::builder()
            ->setServerInfo(
                $this->getConfig('mcp.server.name'),
                $this->getConfig('mcp.server.version'),
                $this->getConfig('mcp.server.description')
            )
            ->setSession($this->globalSessionStore)
            ->setLogger($this->logger);
        
        $discoveryConfig = $this->getConfig('mcp.server.discover', []);
        // configure discovery cache
        $enableCached = (bool) $discoveryConfig['cache']['enable'];
        $discoveryTtl = (int) $discoveryConfig['cache']['ttl'];
        $cacheStoreName = (string) $discoveryConfig['cache']['store'];
        // set discovery
        try {
            $cacheAdapter = $enableCached ? WebmanCache::forDiscovery($cacheStoreName, $discoveryTtl) : null;

            $builder->setDiscovery(
                $discoveryConfig['base_path'],
                $discoveryConfig['scan_dirs'],
                $discoveryConfig['exclude_dirs'],
                $cacheAdapter
            );
            
            $this->logger->info('MCP Discovery configured successfully', [
                'base_path' => $discoveryConfig['base_path'],
                'scan_dirs' => $discoveryConfig['scan_dirs'],
                'exclude_dirs' => $discoveryConfig['exclude_dirs'],
                'cache_enabled' => $enableCached
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to configure MCP Discovery', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
        
        return $builder->build();
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
     * get config
     * @param string $key
     * @return mixed
     */
    private function getConfig(string $key, $default = null): mixed
    {
        return config(sprintf(self::CONFIG_PREFIX, $key), $default);
    }

    /**
     * start channel client
     * this is used to send messages to the client
     * @param Worker $worker
     * @return void
     */
    private function startChannelClient(Worker $worker): void
    {
        Client::connect('127.0.0.1', 2206);
        Client::on('mcp_sse_connects', function ($message){
            if (!isset(self::$mcpSseConnects[$message['session_id']])) {
                self::$mcpSseConnects[$message['session_id']] = $message;
            }
        });
        Client::on('mcp_sse_events', function ($message) use ($worker) {
            if (!isset(self::$mcpSseConnects[$message['session_id']])) {
                return;
            }
            $connectionInfo = self::$mcpSseConnects[$message['session_id']];
            if ($worker->id === $connectionInfo['worker_id']) {
                if (isset($worker->connections[$connectionInfo['connection_id']])) {
                    $connection = $worker->connections[$connectionInfo['connection_id']];
                    $connection->send($this->sendMessage($message['data']), true);
                }
            }
        });
    }
    /**
     * send message to client
     * @param array $content
     * @return string
     */
    private function sendMessage(array $content = []): string
    {
        $message = new ServerSentEvents($content);
        return $message->__toString();
    }
    /**
     * send response to client
     * @param TcpConnection $connection
     * @param array $response
     * @return void
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