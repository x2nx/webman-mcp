<?php
namespace X2nx\WebmanMcp\Transport;

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
class SseHttpTransport implements TransportInterface
{
    /** @var callable(string, ?Uuid): void */
    private $messageListener;

    /** @var callable(Uuid): void */
    private $sessionEndListener;

    private ?Uuid $sessionId = null;

    private static array $activeSessions = [];

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
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $sessionIdString = $this->request->getHeaderLine('mcp-session-id');
        $this->sessionId = $sessionIdString ? Uuid::fromString($sessionIdString) : null;
    }

    public function initialize(): void{}

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
            $this->cache->set(sprintf('mcp:sse:session:active:%s', $this->activeSessionId), $this->sessionId);
        }
        Client::publish('mcp_sse_events', [
            'session_id' => $this->activeSessionId,
            'data'  => [
                'event' => 'message',
                'data' => $data,
            ],
        ]);
    }

    public function listen(): mixed
    {
        Client::connect('127.0.0.1', 2206);
        return match ($this->request->getMethod()) {
            'OPTIONS' => $this->handleOptionsRequest(),
            'GET' => $this->handleGetRequest(),
            'POST' => $this->handlePostRequest(),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->handleUnsupportedRequest(),
        };
    }

    public function onMessage(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function onSessionEnd(callable $listener): void
    {
        $this->sessionEndListener = $listener;
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

        $this->sessionId = $this->cache->get(sprintf('mcp:sse:session:active:%s', $this->activeSessionId));
        
        $body = (string)$this->request->getBody()->getContents();
        
        if (empty($body)) {
            $this->logger->warning('Client sent empty request body.');
            $this->sendJsonResponse(400, [], [
                'error' => 'Bad Request',
                'message' => 'Empty request body.',
            ]);
            return;
        }

        if (\is_callable($this->messageListener)) {
            \call_user_func($this->messageListener, $body, $this->sessionId);
        }

        $this->sendResponse(204, array_merge($this->corsHeaders, [
            'Connection' => 'close',
            'Mcp-Session-Id' => $this->activeSessionId,
        ]), 'Accepted', true, true);
    }

    protected function handleGetRequest()
    {
        if (empty($this->activeSessionId)) {
            $this->activeSessionId = Uuid::v4();
        }

        Client::publish('mcp_sse_connects', [
            'worker_id' => $this->connection->worker->id,
            'connection_id' => $this->connection->id,
            'session_id' => $this->activeSessionId,
        ]);

        $this->sendResponse(200, $this->sseHeaders, "\n" . $this->sendMessage([
            'event' => 'endpoint',
            'data'  => sprintf('/message?sessionId=%s', $this->activeSessionId),
        ]), true, true);
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
        if (\is_callable($this->sessionEndListener)) {
            \call_user_func($this->sessionEndListener, $this->sessionId);
        }
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

    private function sendMessage(array $content = []): string
    {
        $message = new ServerSentEvents($content);
        return $message->__toString();
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