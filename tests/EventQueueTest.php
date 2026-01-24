<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Core\AnalyticsEvent;
use FlagKit\Core\EventQueue;
use FlagKit\Core\EventQueueConfig;
use FlagKit\Core\EventType;
use FlagKit\Types\EvaluationContext;
use PHPUnit\Framework\TestCase;

class EventQueueTest extends TestCase
{
    public function testEnqueueAddsEventToQueue(): void
    {
        $queue = new EventQueue(batchSize: 100);

        $event = new AnalyticsEvent(EventType::Custom);
        $queue->enqueue($event);

        $this->assertEquals(1, $queue->count());
    }

    public function testAutoFlushWhenBatchSizeReached(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 2, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $this->assertEquals(1, $queue->count());

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        // Should have flushed
        $this->assertCount(2, $flushed);
        $this->assertEquals(0, $queue->count());
    }

    public function testFlushSendsEventsToCallback(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 10, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Identify));

        $queue->flush();

        $this->assertCount(2, $flushed);
    }

    public function testFlushAllSendsAllEvents(): void
    {
        $totalFlushed = 0;
        $queue = new EventQueue(batchSize: 2, onFlush: function ($events) use (&$totalFlushed) {
            $totalFlushed += count($events);
        });

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Custom));

        // Should have 1 remaining after auto-flushes (4 flushed in 2 batches)
        $queue->flushAll();

        $this->assertEquals(5, $totalFlushed);
    }

    public function testClearEmptiesQueue(): void
    {
        $queue = new EventQueue(batchSize: 100);

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Custom));

        $queue->clear();

        $this->assertEquals(0, $queue->count());
    }

    public function testTrackEvaluationCreatesEvent(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 100, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->trackEvaluation('flag-key', true, new EvaluationContext('user-123'));
        $queue->flush();

        $this->assertCount(1, $flushed);
        $this->assertEquals(EventType::Evaluation, $flushed[0]->eventType);
        $this->assertEquals('flag-key', $flushed[0]->flagKey);
        $this->assertTrue($flushed[0]->value);
    }

    public function testTrackCustomCreatesEvent(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 100, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->trackCustom('button_clicked', ['button' => 'signup']);
        $queue->flush();

        $this->assertCount(1, $flushed);
        $this->assertEquals(EventType::Track, $flushed[0]->eventType);
        $this->assertEquals('button_clicked', $flushed[0]->data['eventType']);
    }

    public function testTrackIdentifyCreatesEvent(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 100, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->trackIdentify('user-123', ['email' => 'user@example.com']);
        $queue->flush();

        $this->assertCount(1, $flushed);
        $this->assertEquals(EventType::Identify, $flushed[0]->eventType);
        $this->assertEquals('user-123', $flushed[0]->context['userId']);
    }

    public function testTrackPageViewCreatesEvent(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 100, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->trackPageView('/checkout', ['source' => 'cart']);
        $queue->flush();

        $this->assertCount(1, $flushed);
        $this->assertEquals(EventType::PageView, $flushed[0]->eventType);
        $this->assertEquals('/checkout', $flushed[0]->data['page']);
    }

    public function testSetSessionIdAppliestoEvents(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 100, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->setSessionId('session-123');
        $queue->trackCustom('event', null);
        $queue->flush();

        $this->assertEquals('session-123', $flushed[0]->sessionId);
    }

    public function testSetEnvironmentIdAppliesToEvents(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 100, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->setEnvironmentId('env-123');
        $queue->trackCustom('event', null);
        $queue->flush();

        $this->assertEquals('env-123', $flushed[0]->environmentId);
    }

    public function testMaxQueueSizeDropsOldest(): void
    {
        $config = new EventQueueConfig(
            batchSize: 100,
            maxQueueSize: 3
        );
        $queue = new EventQueue(config: $config);

        $queue->enqueue(AnalyticsEvent::custom('event1', null));
        $queue->enqueue(AnalyticsEvent::custom('event2', null));
        $queue->enqueue(AnalyticsEvent::custom('event3', null));
        $queue->enqueue(AnalyticsEvent::custom('event4', null)); // Should drop event1

        $events = $queue->getQueuedEvents();

        $this->assertCount(3, $events);
        // First event should be event2 (event1 was dropped)
        $this->assertEquals('event2', $events[0]->data['eventType']);
    }

    public function testFlushRequeuesOnFailure(): void
    {
        $failCount = 0;
        $queue = new EventQueue(batchSize: 2, onFlush: function ($events) use (&$failCount) {
            $failCount++;
            if ($failCount === 1) {
                throw new \RuntimeException('Flush failed');
            }
        });

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Custom));

        // First flush should fail and requeue
        $this->assertEquals(2, $queue->count());

        // Second flush should succeed
        $queue->flush();
        $this->assertEquals(0, $queue->count());
    }

    public function testGetStatsReturnsCorrectData(): void
    {
        $config = new EventQueueConfig(
            batchSize: 10,
            maxQueueSize: 100,
            sampleRate: 0.5
        );
        $queue = new EventQueue(config: $config);

        $stats = $queue->getStats();

        $this->assertEquals(0, $stats['queueSize']);
        $this->assertEquals(100, $stats['maxQueueSize']);
        $this->assertEquals(10, $stats['batchSize']);
        $this->assertEquals(0.5, $stats['sampleRate']);
    }

    public function testStopFlushesAndCloses(): void
    {
        $flushed = [];
        $queue = new EventQueue(batchSize: 100, onFlush: function ($events) use (&$flushed) {
            $flushed = $events;
        });

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->enqueue(new AnalyticsEvent(EventType::Custom));

        $queue->stop();

        $this->assertCount(2, $flushed);
        $this->assertEquals(0, $queue->count());
    }

    public function testIsFlushingDuringFlush(): void
    {
        $wasFlushingDuringCallback = false;
        $queue = new EventQueue(batchSize: 10, onFlush: function ($events) use (&$wasFlushingDuringCallback, &$queue) {
            $wasFlushingDuringCallback = $queue->isFlushing();
        });

        $queue->enqueue(new AnalyticsEvent(EventType::Custom));
        $queue->flush();

        $this->assertTrue($wasFlushingDuringCallback);
        $this->assertFalse($queue->isFlushing());
    }

    public function testAnalyticsEventToArray(): void
    {
        $event = new AnalyticsEvent(
            eventType: EventType::Custom,
            flagKey: 'flag-key',
            value: true,
            context: ['userId' => 'user-123'],
            data: ['key' => 'value'],
            sessionId: 'session-123',
            environmentId: 'env-123',
            sdkVersion: '1.0.0'
        );

        $array = $event->toArray();

        $this->assertEquals('custom', $array['eventType']);
        $this->assertEquals('flag-key', $array['flagKey']);
        $this->assertTrue($array['value']);
        $this->assertEquals(['userId' => 'user-123'], $array['context']);
        $this->assertEquals(['key' => 'value'], $array['eventData']);
        $this->assertEquals('session-123', $array['sessionId']);
        $this->assertEquals('env-123', $array['environmentId']);
        $this->assertEquals('php', $array['sdkLanguage']);
    }

    public function testAnalyticsEventFactoryMethods(): void
    {
        $evaluation = AnalyticsEvent::evaluation('flag-key', true, null, 'session-123');
        $this->assertEquals(EventType::Evaluation, $evaluation->eventType);
        $this->assertEquals('flag-key', $evaluation->flagKey);

        $custom = AnalyticsEvent::custom('button_clicked', ['button' => 'signup']);
        $this->assertEquals(EventType::Track, $custom->eventType);

        $identify = AnalyticsEvent::identify('user-123', ['email' => 'test@example.com']);
        $this->assertEquals(EventType::Identify, $identify->eventType);

        $pageView = AnalyticsEvent::pageView('/checkout', ['source' => 'cart']);
        $this->assertEquals(EventType::PageView, $pageView->eventType);
    }

    public function testEventQueueConfigDefaults(): void
    {
        $config = EventQueueConfig::default();

        $this->assertEquals(10, $config->batchSize);
        $this->assertEquals(30, $config->flushInterval);
        $this->assertEquals(1000, $config->maxQueueSize);
        $this->assertEquals(3, $config->maxRetries);
        $this->assertEquals(1.0, $config->sampleRate);
    }
}
