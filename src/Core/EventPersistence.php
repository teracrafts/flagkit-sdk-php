<?php

declare(strict_types=1);

namespace FlagKit\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Event status for persistence.
 */
enum EventStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
}

/**
 * Persisted event structure for crash-resilient storage.
 */
class PersistedEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        /** @var array<string, mixed> */
        public readonly array $data,
        public readonly int $timestamp,
        public EventStatus $status = EventStatus::Pending,
        public ?int $sentAt = null
    ) {
    }

    /**
     * Create from an AnalyticsEvent.
     */
    public static function fromAnalyticsEvent(AnalyticsEvent $event): self
    {
        return new self(
            id: self::generateEventId(),
            type: $event->eventType->value,
            data: $event->toArray(),
            timestamp: (int) ($event->timestamp->getTimestamp() * 1000),
            status: EventStatus::Pending
        );
    }

    /**
     * Convert to JSON string for persistence.
     */
    public function toJson(): string
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
            'status' => $this->status->value,
        ];

        if ($this->sentAt !== null) {
            $data['sentAt'] = $this->sentAt;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Create from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            id: $data['id'],
            type: $data['type'],
            data: $data['data'],
            timestamp: $data['timestamp'],
            status: EventStatus::from($data['status']),
            sentAt: $data['sentAt'] ?? null
        );
    }

    /**
     * Generate a unique event ID.
     */
    private static function generateEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(12));
    }
}

/**
 * Crash-resilient event persistence using Write-Ahead Logging (WAL).
 *
 * Persists events to disk before queuing them for sending, ensuring events
 * are not lost during unexpected process termination.
 *
 * Features:
 * - Write-ahead logging with JSON Lines format
 * - File locking for multi-process safety
 * - Buffered writes for performance
 * - Automatic recovery on initialization
 * - Cleanup of old sent events
 */
class EventPersistence
{
    public const DEFAULT_MAX_EVENTS = 10000;
    public const DEFAULT_FLUSH_INTERVAL = 1000;
    public const DEFAULT_BUFFER_SIZE = 100;
    public const LOCK_FILE_NAME = 'flagkit-events.lock';
    public const EVENT_FILE_PREFIX = 'flagkit-events-';
    public const EVENT_FILE_EXTENSION = '.jsonl';

    /** @var PersistedEvent[] */
    private array $buffer = [];

    private string $storagePath;
    private int $maxEvents;
    private int $flushInterval;
    private int $bufferSize;
    private LoggerInterface $logger;
    private ?int $lastFlushTime = null;
    private string $currentLogFile;

    /** @var resource|null */
    private $lockFileHandle = null;

    public function __construct(
        string $storagePath,
        int $maxEvents = self::DEFAULT_MAX_EVENTS,
        int $flushInterval = self::DEFAULT_FLUSH_INTERVAL,
        ?LoggerInterface $logger = null,
        int $bufferSize = self::DEFAULT_BUFFER_SIZE
    ) {
        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $this->maxEvents = $maxEvents;
        $this->flushInterval = $flushInterval;
        $this->bufferSize = $bufferSize;
        $this->logger = $logger ?? new NullLogger();

        $this->ensureStorageDirectory();
        $this->currentLogFile = $this->generateLogFileName();
    }

    /**
     * Persist an event to the buffer.
     * Flushes to disk if buffer is full.
     */
    public function persist(AnalyticsEvent $event): PersistedEvent
    {
        $persistedEvent = PersistedEvent::fromAnalyticsEvent($event);
        $this->buffer[] = $persistedEvent;

        if (count($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }

        return $persistedEvent;
    }

    /**
     * Flush buffered events to disk with file locking.
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        if (!$this->acquireLock()) {
            $this->logger->warning('Failed to acquire lock for event persistence');
            return;
        }

        try {
            $this->writeEventsToFile($this->buffer);
            $this->buffer = [];
            $this->lastFlushTime = (int) (microtime(true) * 1000);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush events to disk', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Mark events as sent after successful batch send.
     *
     * @param string[] $eventIds
     */
    public function markSent(array $eventIds): void
    {
        if (empty($eventIds)) {
            return;
        }

        if (!$this->acquireLock()) {
            $this->logger->warning('Failed to acquire lock for marking events as sent');
            return;
        }

        try {
            $idSet = array_flip($eventIds);
            $sentAt = (int) (microtime(true) * 1000);

            foreach ($this->getEventFiles() as $file) {
                $this->updateEventStatusInFile($file, $idSet, EventStatus::Sent, $sentAt);
            }
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Mark events as sending (in progress).
     *
     * @param string[] $eventIds
     */
    public function markSending(array $eventIds): void
    {
        if (empty($eventIds)) {
            return;
        }

        if (!$this->acquireLock()) {
            $this->logger->warning('Failed to acquire lock for marking events as sending');
            return;
        }

        try {
            $idSet = array_flip($eventIds);

            foreach ($this->getEventFiles() as $file) {
                $this->updateEventStatusInFile($file, $idSet, EventStatus::Sending);
            }
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Mark events as pending (revert from sending on failure).
     *
     * @param string[] $eventIds
     */
    public function markPending(array $eventIds): void
    {
        if (empty($eventIds)) {
            return;
        }

        if (!$this->acquireLock()) {
            $this->logger->warning('Failed to acquire lock for marking events as pending');
            return;
        }

        try {
            $idSet = array_flip($eventIds);

            foreach ($this->getEventFiles() as $file) {
                $this->updateEventStatusInFile($file, $idSet, EventStatus::Pending);
            }
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Recover pending events on startup.
     * Returns events that were not successfully sent (pending or sending status).
     *
     * @return PersistedEvent[]
     */
    public function recover(): array
    {
        // First, flush any buffered events
        $this->flush();

        if (!$this->acquireLock()) {
            $this->logger->warning('Failed to acquire lock for event recovery');
            return [];
        }

        $recoveredEvents = [];

        try {
            foreach ($this->getEventFiles() as $file) {
                $events = $this->readEventsFromFile($file);

                foreach ($events as $event) {
                    // Recover pending and sending events (sending = crashed mid-send)
                    if ($event->status === EventStatus::Pending || $event->status === EventStatus::Sending) {
                        // Reset sending status to pending
                        if ($event->status === EventStatus::Sending) {
                            $event->status = EventStatus::Pending;
                        }
                        $recoveredEvents[] = $event;
                    }
                }
            }

            // Limit to max events
            if (count($recoveredEvents) > $this->maxEvents) {
                $recoveredEvents = array_slice($recoveredEvents, -$this->maxEvents);
            }

            $this->logger->info('Recovered pending events', [
                'count' => count($recoveredEvents),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to recover events', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->releaseLock();
        }

        return $recoveredEvents;
    }

    /**
     * Cleanup old sent events to free disk space.
     * Removes events that have been successfully sent.
     */
    public function cleanup(): void
    {
        if (!$this->acquireLock()) {
            $this->logger->warning('Failed to acquire lock for cleanup');
            return;
        }

        try {
            $totalRemoved = 0;

            foreach ($this->getEventFiles() as $file) {
                $events = $this->readEventsFromFile($file);
                $pendingEvents = [];

                foreach ($events as $event) {
                    // Keep only pending and sending events
                    if ($event->status === EventStatus::Pending || $event->status === EventStatus::Sending) {
                        $pendingEvents[] = $event;
                    } else {
                        $totalRemoved++;
                    }
                }

                if (empty($pendingEvents)) {
                    // Remove empty file
                    @unlink($file);
                } else {
                    // Rewrite file with only pending events
                    $this->rewriteFile($file, $pendingEvents);
                }
            }

            $this->logger->info('Cleaned up sent events', [
                'removed' => $totalRemoved,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to cleanup events', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Get the number of buffered events not yet flushed.
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Get the storage path.
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Close and cleanup resources.
     */
    public function close(): void
    {
        $this->flush();
        $this->releaseLock();
    }

    /**
     * Ensure the storage directory exists.
     */
    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storagePath)) {
            if (!mkdir($this->storagePath, 0755, true)) {
                throw new \RuntimeException("Failed to create storage directory: {$this->storagePath}");
            }
        }
    }

    /**
     * Generate a new log file name.
     */
    private function generateLogFileName(): string
    {
        $timestamp = (int) (microtime(true) * 1000);
        $random = bin2hex(random_bytes(4));

        return $this->storagePath . DIRECTORY_SEPARATOR .
               self::EVENT_FILE_PREFIX . $timestamp . '-' . $random . self::EVENT_FILE_EXTENSION;
    }

    /**
     * Get all event files sorted by modification time.
     *
     * @return string[]
     */
    private function getEventFiles(): array
    {
        $pattern = $this->storagePath . DIRECTORY_SEPARATOR .
                   self::EVENT_FILE_PREFIX . '*' . self::EVENT_FILE_EXTENSION;
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        // Sort by modification time (oldest first)
        usort($files, function ($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });

        return $files;
    }

    /**
     * Acquire file lock for multi-process safety.
     */
    private function acquireLock(): bool
    {
        $lockFile = $this->storagePath . DIRECTORY_SEPARATOR . self::LOCK_FILE_NAME;
        $this->lockFileHandle = @fopen($lockFile, 'c+');

        if ($this->lockFileHandle === false) {
            return false;
        }

        // Acquire exclusive lock with non-blocking option
        if (!flock($this->lockFileHandle, LOCK_EX)) {
            fclose($this->lockFileHandle);
            $this->lockFileHandle = null;
            return false;
        }

        return true;
    }

    /**
     * Release file lock.
     */
    private function releaseLock(): void
    {
        if ($this->lockFileHandle !== null) {
            flock($this->lockFileHandle, LOCK_UN);
            fclose($this->lockFileHandle);
            $this->lockFileHandle = null;
        }
    }

    /**
     * Write events to file.
     *
     * @param PersistedEvent[] $events
     */
    private function writeEventsToFile(array $events): void
    {
        $handle = @fopen($this->currentLogFile, 'a');

        if ($handle === false) {
            throw new \RuntimeException("Failed to open event file: {$this->currentLogFile}");
        }

        try {
            foreach ($events as $event) {
                $line = $event->toJson() . "\n";
                if (fwrite($handle, $line) === false) {
                    throw new \RuntimeException("Failed to write event to file");
                }
            }

            // Ensure data is written to disk
            fflush($handle);

            // fsync for durability (if available)
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read events from a file.
     *
     * @return PersistedEvent[]
     */
    private function readEventsFromFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $events = [];
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                try {
                    $events[] = PersistedEvent::fromJson($line);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to parse event line', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            fclose($handle);
        }

        return $events;
    }

    /**
     * Update event status in a file.
     *
     * @param array<string, int> $idSet Event IDs to update (as keys)
     */
    private function updateEventStatusInFile(
        string $file,
        array $idSet,
        EventStatus $status,
        ?int $sentAt = null
    ): void {
        $events = $this->readEventsFromFile($file);
        $modified = false;

        foreach ($events as $event) {
            if (isset($idSet[$event->id])) {
                $event->status = $status;
                if ($sentAt !== null) {
                    $event->sentAt = $sentAt;
                }
                $modified = true;
            }
        }

        if ($modified) {
            $this->rewriteFile($file, $events);
        }
    }

    /**
     * Rewrite a file with the given events.
     *
     * @param PersistedEvent[] $events
     */
    private function rewriteFile(string $file, array $events): void
    {
        $tempFile = $file . '.tmp';
        $handle = @fopen($tempFile, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Failed to open temp file: {$tempFile}");
        }

        try {
            foreach ($events as $event) {
                $line = $event->toJson() . "\n";
                if (fwrite($handle, $line) === false) {
                    throw new \RuntimeException("Failed to write event to temp file");
                }
            }

            fflush($handle);
            fclose($handle);

            // Atomic rename
            if (!rename($tempFile, $file)) {
                throw new \RuntimeException("Failed to rename temp file");
            }
        } catch (\Throwable $e) {
            @fclose($handle);
            @unlink($tempFile);
            throw $e;
        }
    }
}
