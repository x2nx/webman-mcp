<?php

namespace X2nx\WebmanMcp\Transport;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Mcp\Server\Transport\BaseTransport;
use Mcp\Server\Transport\TransportInterface;

/**
 * @implements TransportInterface<null>
 *
 * @author X2nx <x@x2nx.com>
 */
class MessageTransport extends BaseTransport implements TransportInterface
{
    protected array $messages = [];
    /**
     * @param list<string> $messages
     */
    public function __construct(
        private readonly string $message = '',
        string $sessionId = '',
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($logger);
        if (!empty($sessionId)) {
            $this->sessionId = Uuid::fromString($sessionId);
        }
    }

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id']) && $context['session_id'] instanceof \Symfony\Component\Uid\Uuid) {
            $this->sessionId = $context['session_id'];
        }
        if (!empty($data)) {
            $this->messages[] = [
                'message' => $data,
            ];
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function listen(): mixed
    {
        $this->logger->info('WebmanTransport is processing messages...');

        $this->handleMessage($this->message, $this->sessionId);

        $this->messages = array_merge($this->messages, $this->getOutgoingMessages($this->sessionId));

        $responseMessages = [];
        foreach ($this->messages as $message) {
            $responseMessages[] = [
                'session_id'    => $this->sessionId?->toRfc4122() ?? '',
                'mcp_message'   => $message['message'],
            ];
        }

        $this->logger->info('WebmanTransport finished processing.');

        return $responseMessages;
    }

    public function setSessionId(?Uuid $sessionId): void
    {
        $this->sessionId = $sessionId;
    }
}