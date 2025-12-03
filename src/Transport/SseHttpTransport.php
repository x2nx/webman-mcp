<?php
namespace X2nx\WebmanMcp\Transport;

use Mcp\Server\Transport\BaseTransport;
use Mcp\Server\Transport\TransportInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\ServerSentEvents;
use support\Response;
use Webman\Channel\Client;
use X2nx\WebmanMcp\Cache\Webman as WebmanCache;

/**
 * @author OK Xaas <x@x2nx.com>
 */
class SseHttpTransport extends BaseTransport implements TransportInterface
{
    private ?string $activeSessionId = null;

    private array $sseHeaders = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'X-Accel-Buffering' => 'no',
    ];

    private array $jsonHeaders = [
        'Content-Type' => 'application/json',
    ];

    /** @var array<string, string> */
    private array $corsHeaders = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Authorization, Accept',
    ];

    public function __construct(
        private readonly TcpConnection $connection,
        private readonly ServerRequestInterface $request,
        private readonly WebmanCache $cache,
        LoggerInterface $logger = new NullLogger()
    ) {
        parent::__construct($logger);
        $sessionIdString = $this->request->getHeaderLine('Mcp-Session-Id');
        $this->sessionId = $sessionIdString ? Uuid::fromString($sessionIdString) : null;
    }

    public function send(string $data, array $context): void {
        if (!empty($data)) {
            // $data is a string, not an array, so we send it directly
            $this->sendChannelMessage($data);
        }
    }

    public function listen(): mixed
    {
        $channelHost = config('plugin.x2nx.webman-mcp.channel.host', '127.0.0.1');
        $channelPort = config('plugin.x2nx.webman-mcp.channel.port', 2206);
        Client::connect($channelHost, $channelPort);
        return match ($this->request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'GET' => $this->handleGetRequest(),
            'POST' => $this->handlePostRequest(),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->handleUnsupportedRequest(),
        };
    }

    protected function handleOptionsRequest()
    {
        $this->sendResponse(204, $this->corsHeaders, '');
    }

    protected function handlePostRequest()
    {
        $this->activeSessionId = $this->request->getQueryParams()['sessionId'] ?? null;

        if (empty($this->activeSessionId)) {
            $this->sendJsonResponse(400, $this->corsHeaders + $this->jsonHeaders, [
                'error' => 'Bad Request',
                'message' => 'Session ID is required.',
            ]);
            return;
        }

        $this->sessionId = $this->cache->get(sprintf('mcp_sse_session_active_%s', $this->activeSessionId));

        $body = $this->request->getBody()->getContents();
        
        if (empty($body)) {
            $this->logger->warning('Client sent empty request body.');
            $this->sendJsonResponse(400, [], [
                'error' => 'Bad Request',
                'message' => 'Empty request body.',
            ]);
            return;
        }

        $this->handleMessage($body, $this->sessionId);

        $sessionKey = sprintf('mcp_sse_session_active_%s', $this->activeSessionId);

        if (!empty($this->sessionId) && !$this->cache->has($sessionKey)) {
            $this->cache->set($sessionKey, $this->sessionId);
        }

        $messages = $this->getOutgoingMessages($this->sessionId);

        foreach ($messages as $message) {
            Client::publish('mcp_sse_events', [
                'session_id' => $this->activeSessionId,
                'data'  => $this->formatSseMessage([
                    'event' => 'message',
                    'data' => $message['message'],
                ]),
            ]);
        }

        $this->sendResponse(204, array_merge($this->corsHeaders, [
            'Connection' => 'close',
            'Mcp-Session-Id' => $this->activeSessionId,
        ]), 'Accepted', true, true);
    }

    protected function handleGetRequest()
    {
        if (empty($this->activeSessionId)) {
            $this->activeSessionId = (string) Uuid::v4();
        }

        Client::publish('mcp_sse_connects', [
            'worker_id' => $this->connection->worker->id,
            'connection_id' => $this->connection->id,
            'session_id' => $this->activeSessionId,
        ]);

        try {
            $this->sendResponse(200, $this->sseHeaders, '', true, true);
            $this->sendSseMessage([
                'event' => 'endpoint',
                'data'  => sprintf('/message?sessionId=%s', $this->activeSessionId),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error sending SSE response', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendChannelMessage($e->getMessage());
        }
    }

    protected function handleDeleteRequest()
    {
        if (!$this->sessionId) {
            $this->logger->warning('DELETE request received without session ID.');
            $this->sendJsonResponse(400, [], [
                'error' => 'Bad Request',
                'message' => 'Mcp-Session-Id header is required for DELETE requests.',
            ]);
            return;
        }
        $this->handleSessionEnd($this->sessionId);
        $this->sendResponse(204, $this->corsHeaders, '');
    }

    protected function handleUnsupportedRequest()
    {
        $this->sendJsonResponse(405, $this->corsHeaders, [
            'error' => 'error',
            'message' => 'Method Not Allowed',
        ]);
    }

    public function close(): void {}

    private function sendChannelMessage(string $content = ''): void {
        Client::publish('mcp_sse_events', [
            'session_id' => $this->activeSessionId,
            'data'  => $this->formatSseMessage([
                'event' => 'message',
                'data'  => $content,
            ]),
        ]);
    }

    private function formatSseMessage(array $content = []): string
    {
        $message = new ServerSentEvents($content);
        return $message->__toString();
    }

    private function sendSseMessage(array $content = [], bool $rawContent = true): void
    {
        $message = $this->formatSseMessage($content);
        $this->connection->send($message, $rawContent);
    }

    private function sendJsonResponse(int $status = 200, array $headers = [], array $body = []): void
    {
        $headers = array_merge($headers, $this->jsonHeaders);
        $this->sendResponse($status, $headers, json_encode($body), true);
    }

    private function sendResponse(int $status = 200, array $headers = [], string $body = '', bool $rawContent = false, bool $skipContentLength = false): void
    {
        $response = new Response($status, $headers, $body);
        $response = $response->__toString();
        if ($skipContentLength) {
            $response = preg_replace('/^Content-Length:.*\r\n/mi', '', $response);
        }
        $this->connection->send($response, $rawContent);
    }
}