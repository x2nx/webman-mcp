<?php
namespace X2nx\WebmanMcp\Process;

use Webman\Channel\Client;
use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Connection\TcpConnection;
use Mcp\Server as McpServer;
use Mcp\Server\Builder as McpServerBuilder;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use X2nx\WebmanMcp\Cache\Webman as WebmanCache;
use X2nx\WebmanMcp\Transport\SseHttpTransport;
use X2nx\WebmanMcp\Transport\MessageTransport;
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
     * @var McpServer
     */
    protected McpServer $server;
    /**
     * @var McpServerBuilder
     */
    protected McpServerBuilder $serverBuilder;
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

            $sessionConfig = $this->getConfig('mcp.server.session', []);
            $sessionTtl = (int) ($sessionConfig['ttl'] ?? 3600);
            $sessionStoreName = (string) ($sessionConfig['store'] ?? '');
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
            $this->server = $this->createMcpServer();
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->error('Failed to start MCP Process Worker', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
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
        if (isset($this->logger)) {
            $this->logger->info('MCP Process Worker reloaded');
        }
    }

    /**
     * Worker stopped
     * 
     * @return void
     */
    public function onWorkerStop(): void
    {
        if (isset($this->logger)) {
            $this->logger->info('MCP Process Worker stopped');
        }
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
     * Handle incoming message
     * 
     * @param TcpConnection $connection
     * @param Request $request
     * @return void
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
            if (isset($this->logger)) {
                $this->logger->error('Request handling error', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            $this->handleError($path);
        }
    }

    /**
     * Handle connection error
     * 
     * @param TcpConnection $connection
     * @param int $code
     * @param string $message
     * @return void
     */
    public function onError(TcpConnection $connection, int $code, string $message): void
    {
        if (isset($this->logger)) {
            $this->logger->error('Connection error', [
                'connection_id' => $connection->id,
                'code' => $code,
                'message' => $message
            ]);
        }
        $this->sendResponse(
            $connection, 
            $this->handleError(null, $code, $message)
        );
    }

    /**
     * Handle connection closed
     * 
     * @param TcpConnection $connection
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        // Clear SSE connection info
        if (!isset($this->worker)) {
            return;
        }
        foreach (self::$mcpSseConnects as $sessionId => $connectionInfo) {
            if (isset($connectionInfo['connection_id'], $connectionInfo['worker_id']) 
                && $connectionInfo['connection_id'] === $connection->id 
                && $connectionInfo['worker_id'] === $this->worker->id) {
                unset(self::$mcpSseConnects[$sessionId]);
                if (isset($this->logger)) {
                    $this->logger->debug('SSE connection closed', [
                        'session_id' => $sessionId,
                        'connection_id' => $connection->id
                    ]);
                }
                break;
            }
        }
    }

    /**
     * handle mcp message
     * @param string $message
     * @param string $sessionId
     * @return mixed
     */
    public function handleMessage(string $message = '', string $sessionId = ''): mixed {
        if (empty($message)) {
            return false;
        }
        $this->ensureInitialized();
        $transport = new MessageTransport(
            message: $message,
            sessionId: $sessionId,
            logger: $this->logger
        );
        return $this->server->run($transport);
    }

    /**
     * Handle SSE (Server-Sent Events) message
     * 
     * @param TcpConnection $connection
     * @param Request $request
     * @return array|null Response array or null on error
     */
    private function handleSseMessage(TcpConnection $connection, Request $request): ?array
    {
        try {
            $transport = new SseHttpTransport(
                connection: $connection,
                request: $this->createPsrRequest($request),
                cache: $this->globalSessionStore,
                logger: $this->logger
            );
            
            return $this->server->run($transport);
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
     * Handle MCP stream endpoint message
     * 
     * @param TcpConnection $connection
     * @param Request $request
     * @return array Response array with status, headers, and body
     */
    private function handleMcpMessage(TcpConnection $connection, Request $request): array
    {
        try {
            $transport = new StreamableHttpTransport(
                request: $this->createPsrRequest($request),
                responseFactory: $this->psr17Factory,
                streamFactory: $this->psr17Factory,
                logger: $this->logger
            );
            $response = $this->server->run($transport);
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
     * Handle error and generate error response
     * 
     * @param string|null $path Request path (for logging)
     * @param int $status HTTP status code
     * @param string $message Error message
     * @return array Response array with status, headers, and body
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
     * Create PSR-7 request from Workerman request
     * 
     * @param Request|null $workermanRequest Workerman request object
     * @return ServerRequestInterface PSR-7 server request
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
     * Create and configure MCP server instance
     * 
     * @return McpServer Configured MCP server instance
     * @throws \Throwable If server creation fails
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

        $protocolVersion = $this->getConfig('mcp.server.protocol_version');
        $paginationLimit = $this->getConfig('mcp.server.pagination', 50);
        $instructions = $this->getConfig('mcp.server.instructions', '');
        $capabilities = $this->getConfig('mcp.server.capabilities', []);

        if (!empty($protocolVersion)) {
            $builder->setProtocolVersion($protocolVersion);
        }
        if (!empty($paginationLimit)) {
            $builder->setPaginationLimit($paginationLimit);
        }
        if (!empty($instructions)) {
            $builder->setInstructions($instructions);
        }
        if (!empty($capabilities)) {
            $builder->setCapabilities($capabilities);
        }
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
        
        $this->serverBuilder = $builder;
        $this->server = $this->serverBuilder->build();

        return $this->server;
    }

    /**
     * Get the MCP server builder instance
     * 
     * @return McpServerBuilder
     * @throws \RuntimeException If server builder is not initialized
     */
    public function getServerBuilder(): McpServerBuilder
    {
        if (!isset($this->serverBuilder)) {
            $this->ensureInitialized();
        }
        return $this->serverBuilder;
    }

    /**
     * Set or create the MCP server builder
     * 
     * @param McpServerBuilder|null $serverBuilder Optional builder instance
     * @return self
     */
    public function setServerBuilder(?McpServerBuilder $serverBuilder = null): self
    {
        if ($serverBuilder !== null) {
            $this->serverBuilder = $serverBuilder;
            $this->server = $serverBuilder->build();
        } else {
            $this->ensureInitialized();
        }
        return $this;
    }

    /**
     * Get the MCP server instance
     * 
     * @return McpServer
     * @throws \RuntimeException If server is not initialized
     */
    public function getServer(): McpServer
    {
        if (!isset($this->server)) {
            $this->ensureInitialized();
        }
        return $this->server;
    }

    public static function instance(): self {
        return new self();
    }

    public function setServer(?McpServer $server = null): self
    {
        if ($server !== null) {
            $this->server = $server;
        } else {
            $this->ensureInitialized();
        }
        return $this;
    }

    /**
     * start stdio server
     */
    public function stdioServer(): void
    {
        $this->ensureInitialized();
        $transport = new StdioTransport(logger: $this->logger);
        $this->logger->info('MCP Server started in stdio mode');
        $this->server->run($transport);
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
     * Start channel client for inter-process communication
     * This is used to send messages between workers and handle SSE connections
     * 
     * @param Worker $worker Worker instance
     * @return void
     */
    private function startChannelClient(Worker $worker): void
    {
        Client::connect($this->getConfig('app.channel.host'), $this->getConfig('app.channel.port'));
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
                    $connection->send($message['data'], true);
                }
            }
        });
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

    /**
     * Ensure logger and session store are initialized
     * @return void
     */
    private function ensureInitialized(): void
    {
        if (!isset($this->logger)) {
            $this->logger = Log::channel(sprintf(self::CONFIG_PREFIX, 'mcp'));
        }
        
        if (!isset($this->globalSessionStore)) {
            $sessionConfig = $this->getConfig('mcp.server.session', []);
            $sessionTtl = (int) ($sessionConfig['ttl'] ?? 3600);
            $sessionStoreName = (string) ($sessionConfig['store'] ?? '');
            
            try {
                $this->globalSessionStore = WebmanCache::forSessions($sessionStoreName, $sessionTtl);
                $this->logger->info('Using Webman CacheSessionStore for MCP sessions', [
                    'store' => $sessionStoreName ?: '(default)',
                    'ttl' => $sessionTtl
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to init CacheSessionStore, fallback to FileSessionStore', [
                    'error' => $e->getMessage()
                ]);
                $this->globalSessionStore = new FileSessionStore(runtime_path('sessions'));
            }
        }
        
        if (!isset($this->psr17Factory)) {
            $this->psr17Factory = new Psr17Factory();
        }

        if (!isset($this->server)) {
            $this->createMcpServer();
        }
    }
}