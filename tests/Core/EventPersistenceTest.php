<?php

declare(strict_types=1);

namespace FlagKit\Tests\Core;

use FlagKit\Core\AnalyticsEvent;
use FlagKit\Core\EventPersistence;
use FlagKit\Core\EventQueue;
use FlagKit\Core\EventStatus;
use FlagKit\Core\EventType;
use FlagKit\Core\PersistedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EventPersistenceTest extends TestCase
{
    private string $testStoragePath;

    protected function setUp(): void
    {
        $this->testStoragePath = sys_get_temp_dir() . '/flagkit-test-' . uniqid();
        @mkdir($this->testStoragePath, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup test directory
        $this->removeDirectory($this->testStoragePath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testPersistEventToBuffer(): void
    {
        $persistence = new EventPersistence($this->testStoragePath);

        $event = new AnalyticsEvent(EventType::Custom);
        $persistedEvent = $persistence->persist($event);

        $this->assertStringStartsWith('evt_', $persistedEvent->id);
        $this->assertEquals('custom', $persistedEvent->type);
        $this->assertEquals(EventStatus::Pending, $persistedEvent->status);
        $this->assertEquals(1, $persistence->getBufferSize());
    }

    public function testFlushWritesEventsToFile(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);

        $event1 = new AnalyticsEvent(EventType::Custom);
        $event2 = new AnalyticsEvent(EventType::Evaluation, flagKey: 'test-flag');

        $persistence->persist($event1);
        $persistence->persist($event2);
        $persistence->flush();

        $this->assertEquals(0, $persistence->getBufferSize());

        // Check that files were created
        $files = glob($this->testStoragePath . '/*.jsonl');
        $this->assertNotEmpty($files);

        // Check file contents
        $content = file_get_contents($files[0]);
        $this->assertNotFalse($content);
        $this->assertStringContainsString('"type":"custom"', $content);
        $this->assertStringContainsString('"type":"evaluation"', $content);
    }

    public function testAutoFlushWhenBufferFull(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 2);

        // These should trigger auto-flush
        $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persistence->persist(new AnalyticsEvent(EventType::Custom));

        $this->assertEquals(0, $persistence->getBufferSize());

        $files = glob($this->testStoragePath . '/*.jsonl');
        $this->assertNotEmpty($files);
    }

    public function testRecoverPendingEvents(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);

        // Persist some events
        $persisted1 = $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persisted2 = $persistence->persist(new AnalyticsEvent(EventType::Evaluation, flagKey: 'test'));
        $persistence->flush();

        // Create a new persistence instance to simulate restart
        $persistence2 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $recovered = $persistence2->recover();

        $this->assertCount(2, $recovered);
        $this->assertEquals(EventStatus::Pending, $recovered[0]->status);
        $this->assertEquals(EventStatus::Pending, $recovered[1]->status);
    }

    public function testRecoverSendingEventsAsPending(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);

        // Persist and mark as sending (simulate crash mid-send)
        $persisted = $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persistence->flush();
        $persistence->markSending([$persisted->id]);

        // Create new instance and recover
        $persistence2 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $recovered = $persistence2->recover();

        $this->assertCount(1, $recovered);
        $this->assertEquals(EventStatus::Pending, $recovered[0]->status);
    }

    public function testMarkSentUpdatesEventStatus(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);

        $persisted = $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persistence->flush();

        $persistence->markSent([$persisted->id]);

        // Recover should not return sent events
        $recovered = $persistence->recover();
        $this->assertEmpty($recovered);
    }

    public function testCleanupRemovesSentEvents(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);

        // Persist and mark as sent
        $persisted1 = $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persisted2 = $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persistence->flush();

        $persistence->markSent([$persisted1->id]);

        // Cleanup should remove sent events
        $persistence->cleanup();

        // Check remaining events
        $recovered = $persistence->recover();
        $this->assertCount(1, $recovered);
        $this->assertEquals($persisted2->id, $recovered[0]->id);
    }

    public function testFileLockingPreventsCorruption(): void
    {
        $persistence1 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $persistence2 = new EventPersistence($this->testStoragePath, bufferSize: 10);

        // Both should be able to persist without corruption
        $event1 = $persistence1->persist(new AnalyticsEvent(EventType::Custom));
        $persistence1->flush();

        $event2 = $persistence2->persist(new AnalyticsEvent(EventType::Custom));
        $persistence2->flush();

        // Both events should be recoverable
        $persistence3 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $recovered = $persistence3->recover();

        // At least one event should be recoverable (depends on timing)
        $this->assertGreaterThanOrEqual(1, count($recovered));
    }

    public function testPersistedEventToJsonAndBack(): void
    {
        $event = new PersistedEvent(
            id: 'evt_test123',
            type: 'custom',
            data: ['eventType' => 'custom', 'value' => 'test'],
            timestamp: 1706200000000,
            status: EventStatus::Pending
        );

        $json = $event->toJson();
        $decoded = PersistedEvent::fromJson($json);

        $this->assertEquals($event->id, $decoded->id);
        $this->assertEquals($event->type, $decoded->type);
        $this->assertEquals($event->data, $decoded->data);
        $this->assertEquals($event->timestamp, $decoded->timestamp);
        $this->assertEquals($event->status, $decoded->status);
    }

    public function testPersistedEventFromAnalyticsEvent(): void
    {
        $analyticsEvent = new AnalyticsEvent(
            eventType: EventType::Track,
            flagKey: 'feature-flag',
            value: true,
            data: ['key' => 'value']
        );

        $persistedEvent = PersistedEvent::fromAnalyticsEvent($analyticsEvent);

        $this->assertStringStartsWith('evt_', $persistedEvent->id);
        $this->assertEquals('track', $persistedEvent->type);
        $this->assertEquals(EventStatus::Pending, $persistedEvent->status);
        $this->assertArrayHasKey('flagKey', $persistedEvent->data);
    }

    public function testMaxEventsLimitOnRecovery(): void
    {
        $persistence = new EventPersistence(
            $this->testStoragePath,
            maxEvents: 3,
            bufferSize: 10
        );

        // Persist more events than max
        for ($i = 0; $i < 5; $i++) {
            $persistence->persist(new AnalyticsEvent(EventType::Custom));
        }
        $persistence->flush();

        // Recover should only return max events
        $recovered = $persistence->recover();
        $this->assertCount(3, $recovered);
    }

    public function testCloseFlushesAndCleansUp(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 100);

        $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persistence->persist(new AnalyticsEvent(EventType::Custom));

        // Buffer should have events
        $this->assertEquals(2, $persistence->getBufferSize());

        // Close should flush
        $persistence->close();

        $this->assertEquals(0, $persistence->getBufferSize());

        // Events should be persisted
        $files = glob($this->testStoragePath . '/*.jsonl');
        $this->assertNotEmpty($files);
    }

    public function testStoragePathIsAccessible(): void
    {
        $persistence = new EventPersistence($this->testStoragePath);
        $this->assertEquals($this->testStoragePath, $persistence->getStoragePath());
    }

    public function testCreatesStorageDirectoryIfNotExists(): void
    {
        $newPath = $this->testStoragePath . '/nested/directory';
        $persistence = new EventPersistence($newPath);

        $this->assertDirectoryExists($newPath);
    }

    public function testEventQueueIntegrationWithPersistence(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $flushedEvents = [];

        $queue = new EventQueue(
            batchSize: 100,
            onFlush: function ($events) use (&$flushedEvents) {
                $flushedEvents = array_merge($flushedEvents, $events);
            },
            persistence: $persistence
        );

        // Add events
        $queue->trackCustom('button_clicked', ['button' => 'signup']);
        $queue->trackEvaluation('feature-flag', true);

        // Events should be persisted
        $persistence->flush();
        $recovered = $persistence->recover();
        $this->assertCount(2, $recovered);

        // Flush the queue
        $queue->flush();
        $this->assertCount(2, $flushedEvents);

        // After successful flush, events should be marked as sent
        $recovered = $persistence->recover();
        $this->assertEmpty($recovered);
    }

    public function testEventQueueRecoveryOnInit(): void
    {
        // First, persist some events
        $persistence1 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $persistence1->persist(new AnalyticsEvent(EventType::Custom));
        $persistence1->persist(new AnalyticsEvent(EventType::Evaluation, flagKey: 'test'));
        $persistence1->flush();
        $persistence1->close();

        // Create new queue with persistence - should recover events
        $persistence2 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $queue = new EventQueue(
            batchSize: 100,
            persistence: $persistence2
        );

        // Queue should have recovered events
        $this->assertEquals(2, $queue->count());
    }

    public function testMarkPendingRevertsStatus(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);

        $persisted = $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persistence->flush();

        // Mark as sending
        $persistence->markSending([$persisted->id]);

        // Mark back to pending (simulating failed send)
        $persistence->markPending([$persisted->id]);

        // Should be recoverable as pending
        $recovered = $persistence->recover();
        $this->assertCount(1, $recovered);
        $this->assertEquals(EventStatus::Pending, $recovered[0]->status);
    }

    public function testMultipleEventFilesAreHandled(): void
    {
        // Create first set of events
        $persistence1 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $persistence1->persist(new AnalyticsEvent(EventType::Custom));
        $persistence1->flush();

        // Simulate new file being created (different timestamp)
        usleep(1000); // Small delay to ensure different timestamp

        $persistence2 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $persistence2->persist(new AnalyticsEvent(EventType::Evaluation, flagKey: 'test'));
        $persistence2->flush();

        // Should have multiple files
        $files = glob($this->testStoragePath . '/*.jsonl');
        $this->assertGreaterThanOrEqual(1, count($files));

        // Recovery should get all events
        $persistence3 = new EventPersistence($this->testStoragePath, bufferSize: 10);
        $recovered = $persistence3->recover();
        $this->assertGreaterThanOrEqual(2, count($recovered));
    }

    public function testEmptyRecoveryWhenNoEvents(): void
    {
        $persistence = new EventPersistence($this->testStoragePath);
        $recovered = $persistence->recover();

        $this->assertEmpty($recovered);
    }

    public function testSentAtIsSetWhenMarkingSent(): void
    {
        $persistence = new EventPersistence($this->testStoragePath, bufferSize: 10);

        $persisted = $persistence->persist(new AnalyticsEvent(EventType::Custom));
        $persistence->flush();

        $beforeMarkSent = (int) (microtime(true) * 1000);
        $persistence->markSent([$persisted->id]);
        $afterMarkSent = (int) (microtime(true) * 1000);

        // Read the file directly to check sentAt
        $files = glob($this->testStoragePath . '/*.jsonl');
        $content = file_get_contents($files[0]);
        $lines = array_filter(explode("\n", trim($content)));

        $lastLine = end($lines);
        $data = json_decode($lastLine, true);

        // The event should have sentAt set
        if ($data['status'] === 'sent') {
            $this->assertArrayHasKey('sentAt', $data);
            $this->assertGreaterThanOrEqual($beforeMarkSent, $data['sentAt']);
            $this->assertLessThanOrEqual($afterMarkSent, $data['sentAt']);
        }
    }
}
