<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Core\Cache;
use FlagKit\Core\FlagCache;
use FlagKit\Types\FlagState;
use FlagKit\Types\FlagValue;
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

        $all = $cache->getAllFlags();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('flag1', $all);
        $this->assertArrayHasKey('flag2', $all);
    }

    // New tests for stale value support

    public function testGetStaleReturnsExpiredValue(): void
    {
        $cache = new Cache(ttl: 1);
        $cache->set('key', 'value');

        sleep(2);

        // get() returns null for expired entries
        $this->assertNull($cache->get('key'));
        // getStale() returns the value even if expired
        $this->assertEquals('value', $cache->getStale('key'));
    }

    public function testIsStaleReturnsTrueForExpiredEntry(): void
    {
        $cache = new Cache(ttl: 1);
        $cache->set('key', 'value');

        sleep(2);

        $this->assertTrue($cache->isStale('key'));
    }

    public function testIsStaleReturnsFalseForValidEntry(): void
    {
        $cache = new Cache(ttl: 3600);
        $cache->set('key', 'value');

        $this->assertFalse($cache->isStale('key'));
    }

    public function testGetEntryReturnsFullMetadata(): void
    {
        $cache = new Cache();
        $cache->set('key', 'value');

        $entry = $cache->getEntry('key');

        $this->assertNotNull($entry);
        $this->assertEquals('value', $entry->value);
        $this->assertGreaterThan(0, $entry->fetchedAt);
        $this->assertGreaterThan($entry->fetchedAt, $entry->expiresAt);
    }

    public function testGetAllKeysReturnsAllKeys(): void
    {
        $cache = new Cache();
        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');

        $keys = $cache->getAllKeys();

        $this->assertCount(2, $keys);
        $this->assertContains('key1', $keys);
        $this->assertContains('key2', $keys);
    }

    public function testGetAllValidExcludesExpiredEntries(): void
    {
        $cache = new Cache(ttl: 3600);
        $cache->set('valid', 'value');
        $cache->set('expired', 'value', 1);

        sleep(2);

        $valid = $cache->getAllValid();

        $this->assertCount(1, $valid);
        $this->assertArrayHasKey('valid', $valid);
        $this->assertArrayNotHasKey('expired', $valid);
    }

    public function testCountValidAndCountStale(): void
    {
        $cache = new Cache(ttl: 3600);
        $cache->set('valid1', 'value');
        $cache->set('valid2', 'value');
        $cache->set('expired', 'value', 1);

        sleep(2);

        $this->assertEquals(2, $cache->countValid());
        $this->assertEquals(1, $cache->countStale());
    }

    public function testPurgeStaleRemovesExpiredEntries(): void
    {
        $cache = new Cache(ttl: 3600);
        $cache->set('valid', 'value');
        $cache->set('expired1', 'value', 1);
        $cache->set('expired2', 'value', 1);

        sleep(2);

        $removed = $cache->purgeStale();

        $this->assertEquals(2, $removed);
        $this->assertEquals(1, $cache->count());
    }

    public function testExportAndImport(): void
    {
        $cache1 = new Cache(ttl: 3600);
        $cache1->set('key1', 'value1');
        $cache1->set('key2', 'value2');

        $exported = $cache1->export();

        $cache2 = new Cache();
        $cache2->import($exported);

        $this->assertEquals('value1', $cache2->get('key1'));
        $this->assertEquals('value2', $cache2->get('key2'));
    }

    public function testGetStatsReturnsCorrectCounts(): void
    {
        $cache = new Cache(maxSize: 100, ttl: 3600);
        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $cache->set('expired', 'value', 1);

        sleep(2);

        $stats = $cache->getStats();

        $this->assertEquals(3, $stats['size']);
        $this->assertEquals(2, $stats['validCount']);
        $this->assertEquals(1, $stats['staleCount']);
        $this->assertEquals(100, $stats['maxSize']);
    }

    public function testIsEmptyReturnsTrueForEmptyCache(): void
    {
        $cache = new Cache();

        $this->assertTrue($cache->isEmpty());

        $cache->set('key', 'value');

        $this->assertFalse($cache->isEmpty());
    }

    public function testFlagCacheHasFlag(): void
    {
        $cache = new FlagCache();
        $cache->set('flag1', new FlagState('flag1', FlagValue::from(true)));

        $this->assertTrue($cache->hasFlag('flag1'));
        $this->assertFalse($cache->hasFlag('flag2'));
    }

    public function testFlagCacheGetAllFlagKeys(): void
    {
        $cache = new FlagCache();
        $cache->set('flag1', new FlagState('flag1', FlagValue::from(true)));
        $cache->set('flag2', new FlagState('flag2', FlagValue::from('test')));

        $keys = $cache->getAllFlagKeys();

        $this->assertCount(2, $keys);
        $this->assertContains('flag1', $keys);
        $this->assertContains('flag2', $keys);
    }

    public function testFlagCacheGetStaleFlagReturnsExpiredFlag(): void
    {
        $cache = new FlagCache(ttl: 1);
        $flag = new FlagState('flag1', FlagValue::from(true));
        $cache->set('flag1', $flag);

        sleep(2);

        $this->assertNull($cache->get('flag1'));
        $this->assertNotNull($cache->getStaleFlag('flag1'));
        $this->assertEquals('flag1', $cache->getStaleFlag('flag1')->key);
    }
}
