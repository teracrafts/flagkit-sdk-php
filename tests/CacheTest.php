<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Cache;
use FlagKit\FlagCache;
use FlagKit\FlagState;
use FlagKit\FlagValue;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    public function testSetAndGetReturnsValue(): void
    {
        $cache = new Cache();

        $cache->set('key', 'value');
        $result = $cache->get('key');

        $this->assertEquals('value', $result);
    }

    public function testGetNonExistentReturnsNull(): void
    {
        $cache = new Cache();

        $result = $cache->get('nonexistent');

        $this->assertNull($result);
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $cache = new Cache();
        $cache->set('key', 'value');

        $this->assertTrue($cache->has('key'));
    }

    public function testHasReturnsFalseForNonExistentKey(): void
    {
        $cache = new Cache();

        $this->assertFalse($cache->has('nonexistent'));
    }

    public function testRemoveDeletesEntry(): void
    {
        $cache = new Cache();
        $cache->set('key', 'value');

        $cache->remove('key');

        $this->assertFalse($cache->has('key'));
    }

    public function testClearRemovesAllEntries(): void
    {
        $cache = new Cache();
        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');

        $cache->clear();

        $this->assertEquals(0, $cache->count());
    }

    public function testExpiredEntryIsNotReturned(): void
    {
        $cache = new Cache(ttl: 1);
        $cache->set('key', 'value');

        sleep(2);

        $this->assertNull($cache->get('key'));
    }

    public function testEvictionWhenOverMaxSize(): void
    {
        $cache = new Cache(maxSize: 3, ttl: 3600);

        $cache->set('key1', 1);
        usleep(5000);
        $cache->set('key2', 2);
        usleep(5000);
        $cache->set('key3', 3);
        usleep(5000);
        $cache->set('key4', 4);

        $this->assertLessThanOrEqual(3, $cache->count());
    }

    public function testFlagCacheSetAllStoresMultipleFlags(): void
    {
        $cache = new FlagCache();
        $flags = [
            new FlagState('flag1', FlagValue::from(true)),
            new FlagState('flag2', FlagValue::from('test')),
        ];

        $cache->setAll($flags);

        $this->assertEquals(2, $cache->count());
        $this->assertNotNull($cache->get('flag1'));
        $this->assertNotNull($cache->get('flag2'));
    }

    public function testFlagCacheGetAllReturnsAllFlags(): void
    {
        $cache = new FlagCache();
        $cache->set('flag1', new FlagState('flag1', FlagValue::from(true)));
        $cache->set('flag2', new FlagState('flag2', FlagValue::from('test')));

        $all = $cache->getAll();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('flag1', $all);
        $this->assertArrayHasKey('flag2', $all);
    }
}
