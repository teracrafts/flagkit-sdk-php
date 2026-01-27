<?php

declare(strict_types=1);

namespace FlagKit\Core;

use FlagKit\Types\FlagState;

/**
 * Connection states for streaming.
 */
enum StreamingState: string
{
    case DISCONNECTED = 'disconnected';
    case CONNECTING = 'connecting';
    case CONNECTED = 'connected';
    case RECONNECTING = 'reconnecting';
    case FAILED = 'failed';
}

/**
 * Response from the stream token endpoint.
 */
class StreamTokenResponse
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresIn
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            token: $data['token'],
            expiresIn: $data['expiresIn']
        );
    }
}

/**
 * Streaming configuration.
 */
class StreamingConfig
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly float $reconnectInterval = 3.0,
        public readonly int $maxReconnectAttempts = 3,
        public readonly float $heartbeatInterval = 30.0
    ) {}

    public static function default(): self
    {
        return new self();
    }
}

/**
 * Manages Server-Sent Events (SSE) connection for real-time flag updates.
 *
 * Security: Uses token exchange pattern to avoid exposing API keys in URLs.
 * 1. Fetches short-lived token via POST with API key in header
 * 2. Connects to SSE endpoint with disposable token in URL
 *
 * Features:
 * - Secure token-based authentication
 * - Automatic token refresh before expiry
 * - Automatic reconnection with exponential backoff
 * - Graceful degradation to polling after max failures
 * - Heartbeat monitoring for connection health
 *
 * Note: PHP's synchronous nature limits real SSE support.
 * This implementation uses polling with short intervals to simulate streaming.
 * For true SSE, consider using ReactPHP or Swoole.
 */
class StreamingManager
{
    private StreamingState $state = StreamingState::DISCONNECTED;
    private int $consecutiveFailures = 0;
    private float $lastHeartbeat;
    private ?string $currentToken = null;
    private float $tokenExpiry = 0;
    private bool $stopRequested = false;

    /**
     * @param string $baseUrl Base URL for API endpoints
     * @param callable(): string $getApiKey Function to get the current API key
     * @param StreamingConfig $config Streaming configuration
     * @param callable(FlagState): void $onFlagUpdate Callback when a flag is updated
     * @param callable(string): void $onFlagDelete Callback when a flag is deleted
     * @param callable(list<FlagState>): void $onFlagsReset Callback when all flags are reset
     * @param callable(): void $onFallbackToPolling Callback when streaming fails
     */
    public function __construct(
        private readonly string $baseUrl,
        private $getApiKey,
        private readonly StreamingConfig $config,
        private $onFlagUpdate,
        private $onFlagDelete,
        private $onFlagsReset,
        private $onFallbackToPolling,
        private ?\Psr\Log\LoggerInterface $logger = null
    ) {
        $this->lastHeartbeat = microtime(true);
    }

    /**
     * Gets the current connection state.
     */
    public function getState(): StreamingState
    {
        return $this->state;
    }

    /**
     * Checks if streaming is connected.
     */
    public function isConnected(): bool
    {
        return $this->state === StreamingState::CONNECTED;
    }

    /**
     * Starts the streaming connection.
     *
     * Note: In PHP, this is typically called in a loop or with ReactPHP.
     */
    public function connect(): void
    {
        if ($this->state === StreamingState::CONNECTED ||
            $this->state === StreamingState::CONNECTING) {
            return;
        }

        $this->state = StreamingState::CONNECTING;
        $this->stopRequested = false;
        $this->initiateConnection();
    }

    /**
     * Stops the streaming connection.
     */
    public function disconnect(): void
    {
        $this->stopRequested = true;
        $this->state = StreamingState::DISCONNECTED;
        $this->consecutiveFailures = 0;
        $this->currentToken = null;
    }

    /**
     * Retries the streaming connection.
     */
    public function retryConnection(): void
    {
        if ($this->state === StreamingState::CONNECTED ||
            $this->state === StreamingState::CONNECTING) {
            return;
        }
        $this->consecutiveFailures = 0;
        $this->connect();
    }

    /**
     * Polls for events once (for use in a loop).
     */
    public function poll(): void
    {
        if ($this->state !== StreamingState::CONNECTED) {
            return;
        }

        // Check if token needs refresh
        if ($this->currentToken !== null && microtime(true) > $this->tokenExpiry * 0.8) {
            $this->refreshToken();
        }

        // In a real implementation, this would read from an open stream
        // For PHP without ReactPHP, we simulate by checking for updates
        $this->checkHeartbeat();
    }

    private function initiateConnection(): void
    {
        try {
            // Step 1: Fetch short-lived stream token
            $tokenResponse = $this->fetchStreamToken();
            $this->currentToken = $tokenResponse->token;
            $this->tokenExpiry = microtime(true) + $tokenResponse->expiresIn;

            // Step 2: Mark as connected
            $this->handleOpen();

            // Note: In true SSE, we'd open a persistent connection here
            // For PHP, the calling code should call poll() repeatedly

        } catch (\Exception $e) {
            $this->logger?->error('Failed to fetch stream token: ' . $e->getMessage());
            $this->handleConnectionFailure();
        }
    }

    private function fetchStreamToken(): StreamTokenResponse
    {
        $tokenUrl = $this->baseUrl . '/sdk/stream/token';

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . ($this->getApiKey)(),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException("Failed to fetch stream token: HTTP $httpCode");
        }

        $data = json_decode($response, true);
        return StreamTokenResponse::fromArray($data);
    }

    private function refreshToken(): void
    {
        try {
            $tokenResponse = $this->fetchStreamToken();
            $this->currentToken = $tokenResponse->token;
            $this->tokenExpiry = microtime(true) + $tokenResponse->expiresIn;
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to refresh stream token, reconnecting: ' . $e->getMessage());
            $this->disconnect();
            $this->connect();
        }
    }

    private function handleOpen(): void
    {
        $this->state = StreamingState::CONNECTED;
        $this->consecutiveFailures = 0;
        $this->lastHeartbeat = microtime(true);
        $this->logger?->info('Streaming connected');
    }

    /**
     * Process an SSE event (called by stream reader).
     */
    public function processEvent(string $eventType, string $data): void
    {
        try {
            switch ($eventType) {
                case 'flag_updated':
                    $flagData = json_decode($data, true);
                    $flag = FlagState::fromArray($flagData);
                    ($this->onFlagUpdate)($flag);
                    break;

                case 'flag_deleted':
                    $deleteData = json_decode($data, true);
                    ($this->onFlagDelete)($deleteData['key']);
                    break;

                case 'flags_reset':
                    $flagsData = json_decode($data, true);
                    $flags = array_map(
                        fn($f) => FlagState::fromArray($f),
                        $flagsData
                    );
                    ($this->onFlagsReset)($flags);
                    break;

                case 'heartbeat':
                    $this->lastHeartbeat = microtime(true);
                    break;
            }
        } catch (\Exception $e) {
            $this->logger?->warning("Failed to process event $eventType: " . $e->getMessage());
        }
    }

    private function checkHeartbeat(): void
    {
        $timeSince = microtime(true) - $this->lastHeartbeat;
        $threshold = $this->config->heartbeatInterval * 2;

        if ($timeSince > $threshold) {
            $this->logger?->warning("Heartbeat timeout, reconnecting. Time since: {$timeSince}s");
            $this->handleConnectionFailure();
        }
    }

    private function handleConnectionFailure(): void
    {
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= $this->config->maxReconnectAttempts) {
            $this->state = StreamingState::FAILED;
            $this->logger?->warning(
                "Streaming failed, falling back to polling. Failures: {$this->consecutiveFailures}"
            );
            ($this->onFallbackToPolling)();
        } else {
            $this->state = StreamingState::RECONNECTING;
            $this->scheduleReconnect();
        }
    }

    private function scheduleReconnect(): void
    {
        $delay = $this->getReconnectDelay();
        $this->logger?->debug(
            "Scheduling reconnect in {$delay}s, attempt {$this->consecutiveFailures}"
        );

        // In PHP, this would typically be handled by the calling code
        // sleep((int) $delay);
        // $this->connect();
    }

    private function getReconnectDelay(): float
    {
        $baseDelay = $this->config->reconnectInterval;
        $backoff = pow(2, $this->consecutiveFailures - 1);
        $delay = $baseDelay * $backoff;
        // Cap at 30 seconds
        return min($delay, 30.0);
    }

    /**
     * Gets the current stream URL with token (for external SSE libraries).
     */
    public function getStreamUrl(): ?string
    {
        if ($this->currentToken === null) {
            return null;
        }
        return $this->baseUrl . '/sdk/stream?token=' . urlencode($this->currentToken);
    }

    /**
     * Ensures a valid token is available and returns it.
     */
    public function ensureToken(): string
    {
        if ($this->currentToken === null || microtime(true) > $this->tokenExpiry * 0.8) {
            $tokenResponse = $this->fetchStreamToken();
            $this->currentToken = $tokenResponse->token;
            $this->tokenExpiry = microtime(true) + $tokenResponse->expiresIn;
        }
        return $this->currentToken;
    }
}
