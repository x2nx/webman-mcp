<?php
namespace X2nx\WebmanMcp\Process;

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Connection\TcpConnection;

use Mcp\Server as McpServer;
use Mcp\Server\Transport\TransportInterface;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use X2nx\WebmanMcp\Transport\SseHttpTransport;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use support\Response;
use support\Log;

class Server
{
    /**
     * @var McpServer
     */
    protected McpServer $server;
    /**
     * @var Worker
     */
    protected Worker $worker;
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    protected TcpConnection $connection;

    protected TransportInterface $transport;

    protected Psr17Factory $psr17Factory;

    /**
     * 在 Worker 启动时执行
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        $configPrefix = 'plugin.x2nx.webman-mcp.%s';
        $this->logger = Log::channel(
            config(sprintf($configPrefix, 'process.mcp.constructor.logger')) ?? 'default'
        );
        $this->worker = $worker;
        $this->initializeMcpServer();
    }

    /**
     * 在 Worker 重新加载时执行
     * @return void
     */
    public function onWorkerReload(): void
    {
        $this->logger->info('MCP Server reloaded');
    }

    /**
     * 在 Worker 停止时执行
     * @return void
     */
    public function onWorkerStop(): void
    {
        $this->logger->info('MCP Server stopped');
    }

    /**
     * 在连接建立时执行
     * @param TcpConnection $connection
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        $this->logger->info('MCP Server connected');
    }

    /**
     * 在消息到达时执行
     * @param TcpConnection $connection
     * @param Request $request
     * @return void
     */
    public function onMessage(TcpConnection $connection, Request $request): void
    {
        $response = match ($request->path()) {
            '/mcp' => $this->streamServer($request),
            '/sse', '/message' => $this->httpServer($connection, $request),
            default => [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'error' => 'Not Found',
                    'message' => 'Not Found',
                ]),
            ],
        };
        if (!empty($response)) {
            $this->logger->info(sprintf('MCP Server response: %s', $response['body']));
            $connection->send(new Response(
                $response['status'],
                $response['headers'],
                $response['body']
            ));
        }
    }

    /**
     * 在错误发生时执行
     * @param TcpConnection $connection
     * @param int $code
     * @param string $msg
     * @return void
     */
    public function onError(TcpConnection $connection, $code, $msg): void
    {
        $this->logger->error(
            sprintf(
                'MCP Server error: %s, code: %s',
                $msg,
                $code
            )
        );
    }

    /**
     * 在连接关闭时执行
     * @param TcpConnection $connection
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $this->logger->info('MCP Server closed');
    }

    /**
     * 初始化 MCP 服务器
     * @return void
     */
    private function initializeMcpServer(): void
    {
        $configPrefix = 'plugin.x2nx.webman-mcp.%s';
        $logChannel = config(sprintf($configPrefix, 'process.mcp.constructor.logger')) ?? 'default';
        $this->logger = Log::channel(
            config(sprintf($configPrefix, 'process.mcp.constructor.logger')) ?? 'default'
        );
        $this->server = McpServer::builder()
            ->setServerInfo(
                config(sprintf($configPrefix, 'mcp.server.name')),
                config(sprintf($configPrefix, 'mcp.server.version')),
                config(sprintf($configPrefix, 'mcp.server.description')),
            )->setDiscovery(
                config(sprintf($configPrefix, 'mcp.server.discover.base_path'), base_path()),
                config(sprintf($configPrefix, 'mcp.server.discover.scan_dirs'), [
                    'app/mcp',
                ]),
                config(sprintf($configPrefix, 'mcp.server.discover.exclude_dirs'), []),
            )
            ->setLogger($this->logger)->build();
    }

    /**
     * 启动 Http 传输
     */
    public function httpServer($connection, $request): void {
        $this->transport = new SseHttpTransport(
            connection: $connection,
            request: $request,
            logger: $this->logger,
        );
        $this->server->connect($this->transport);
        $this->transport->listen();
    }

    /**
     *  启动 Stream 传输
     * @param $request
     * @return Response|array
     */
    public function streamServer($request): Response|array
    {
        $this->psr17Factory = new Psr17Factory();
        try {
            // Convert Workerman Request to PSR-7 ServerRequest
            $psrRequest = $this->psr17Factory->createServerRequest($request->method(), $request->uri(), []);

            // Headers (support array or string values)
            foreach ((array)$request->header() as $name => $value) {
                if ($value === null) {
                    continue;
                }
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $psrRequest = $psrRequest->withAddedHeader($name, (string)$v);
                    }
                } else {
                    $psrRequest = $psrRequest->withHeader($name, (string)$value);
                }
            }

            // Cookies, query params, parsed body
            $psrRequest = $psrRequest
                ->withCookieParams((array)$request->cookie())
                ->withQueryParams((array)$request->get())
                ->withParsedBody((array)$request->post());

            // Body
            $rawBody = '';
            $method = strtoupper($request->method());
            if ($method !== 'GET' && $method !== 'HEAD') {
                $rawBody = method_exists($request, 'rawBody') ? (string)$request->rawBody() : '';
            }
            $psrBody = $this->psr17Factory->createStream($rawBody);
            $psrRequest = $psrRequest->withBody($psrBody);

            $this->transport = new StreamableHttpTransport(
                request: $psrRequest,
                responseFactory: $this->psr17Factory,
                streamFactory: $this->psr17Factory,
                logger: $this->logger,
            );
            $this->server->connect($this->transport);
            $psrResponse = $this->transport->listen();

            // Convert PSR-7 Response to Webman Response
            $status = $psrResponse->getStatusCode();
            $headers = $psrResponse->getHeaders();
            $body = (string)$psrResponse->getBody();
            return [
                'status' => $status,
                'headers' => $headers,
                'body' => $body,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('MCP Stream transport error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'error' => 'Internal Server Error',
                    'message' => 'MCP stream transport failure'
                ]),
            ];
        }
    }

    /**
     * 启动 Stdio 传输
     * @return void
     */
    public function stdioServer(): void
    {
        $this->initializeMcpServer();
        $this->logger->info('MCP Server started on stdio transport');
        $this->transport = new StdioTransport(
            logger: $this->logger,
        );
        $this->server->connect($this->transport);
        $this->transport->listen();
    }
}