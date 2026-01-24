<?php

declare(strict_types=1);

namespace FlagKit\Core;

use FlagKit\Types\FlagState;

/**
 * Cache entry with metadata.
 */
class CacheEntry
{
    public function __construct(
        public readonly mixed $value,
        public readonly int $fetchedAt,
        public readonly int $expiresAt,
        public int $lastAccessed
    ) {
    }

    /**
     * Check if this entry is expired.
     */
    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    /**
     * Check if this entry is stale (expired but still present).
     */
    public function isStale(): bool
    {
        return $this->isExpired();
    }
}

/**
 * In-memory cache with TTL support.
 *
 * Features:
 * - Time-to-live (TTL) support
 * - Maximum entry limit with LRU eviction
 * - Stale value fallback support
 * - Thread-safe operations (within PHP's execution model)
 *
 * @template TKey of string
 * @template TValue
 */
class Cache
{
    /** @var array<TKey, CacheEntry> */
    private array $cache = [];

    private int $maxSize;
    private int $ttl;

    public function __construct(int $maxSize = 1000, int $ttl = 300)
    {
        $this->maxSize = $maxSize;
        $this->ttl = $ttl;
    }

    /**
     * Get a value from cache.
     * Returns null if not found or expired.
     *
     * @param TKey $key
     * @return TValue|null
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        $entry = $this->cache[$key];
        if ($entry->isExpired()) {
            // Don't delete - keep for stale fallback
            return null;
        }

        $entry->lastAccessed = time();
        return $entry->value;
    }

    /**
     * Get a cache entry with full metadata.
     *
     * @param TKey $key
     */
    public function getEntry(string $key): ?CacheEntry
    {
        return $this->cache[$key] ?? null;
    }

    /**
     * Check if a cached entry is stale (expired but still available).
     *
     * @param TKey $key
     */
    public function isStale(string $key): bool
    {
        $entry = $this->cache[$key] ?? null;
        if ($entry === null) {
            return false;
        }
        return $entry->isStale();
    }

    /**
     * Get a stale value (even if expired).
     * Useful for fallback when network is unavailable.
     *
     * @param TKey $key
     * @return TValue|null
     */
    public function getStale(string $key): mixed
    {
        $entry = $this->cache[$key] ?? null;
        return $entry?->value;
    }

    /**
     * Get a stale value with alias for getStaleValue.
     *
     * @param TKey $key
     * @return TValue|null
     */
    public function getStaleValue(string $key): mixed
    {
        return $this->getStale($key);
    }

    /**
     * Set a value in cache.
     *
     * @param TKey $key
     * @param TValue $value
     */
    public function set(string $key, mixed $value, ?int $customTtl = null): void
    {
        $ttl = $customTtl ?? $this->ttl;
        $now = time();

        $this->cache[$key] = new CacheEntry(
            value: $value,
            fetchedAt: $now,
            expiresAt: $now + $ttl,
            lastAccessed: $now
        );

        $this->evictIfNeeded();
    }

    /**
     * Set multiple values in cache.
     *
     * @param array<TKey, TValue> $items
     */
    public function setMany(array $items, ?int $customTtl = null): void
    {
        foreach ($items as $key => $value) {
            $this->set($key, $value, $customTtl);
        }
    }

    /**
     * Check if key exists in cache (valid, not expired).
     *
     * @param TKey $key
     */
    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        return !$this->cache[$key]->isExpired();
    }

    /**
     * Check if key exists in cache (including stale entries).
     *
     * @param TKey $key
     */
    public function hasAny(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * Delete a key from cache.
     *
     * @param TKey $key
     */
    public function delete(string $key): bool
    {
        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            return true;
        }
        return false;
    }

    /**
     * Alias for delete.
     *
     * @param TKey $key
     */
    public function remove(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * Clear all entries from cache.
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Get the number of entries in cache.
     */
    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * Get the number of valid (non-expired) entries.
     */
    public function countValid(): int
    {
        $count = 0;
        foreach ($this->cache as $entry) {
            if (!$entry->isExpired()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get the number of stale entries.
     */
    public function countStale(): int
    {
        return $this->count() - $this->countValid();
    }

    /**
     * Get all keys in cache.
     *
     * @return array<TKey>
     */
    public function keys(): array
    {
        return array_keys($this->cache);
    }

    /**
     * Alias for keys() to match spec.
     *
     * @return array<TKey>
     */
    public function getAllKeys(): array
    {
        return $this->keys();
    }

    /**
     * Get all valid (non-expired) values.
     *
     * @return array<TKey, TValue>
     */
    public function getAllValid(): array
    {
        $result = [];
        foreach ($this->cache as $key => $entry) {
            if (!$entry->isExpired()) {
                $result[$key] = $entry->value;
            }
        }
        return $result;
    }

    /**
     * Get all values including stale ones.
     *
     * @return array<TKey, TValue>
     */
    public function getAll(): array
    {
        $result = [];
        foreach ($this->cache as $key => $entry) {
            $result[$key] = $entry->value;
        }
        return $result;
    }

    /**
     * Check if the cache is empty.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return [
            'size' => $this->count(),
            'validCount' => $this->countValid(),
            'staleCount' => $this->countStale(),
            'maxSize' => $this->maxSize,
        ];
    }

    /**
     * Export cache data for persistence.
     *
     * @return array<TKey, array<string, mixed>>
     */
    public function export(): array
    {
        $data = [];
        foreach ($this->cache as $key => $entry) {
            $data[$key] = [
                'value' => $entry->value,
                'fetchedAt' => $entry->fetchedAt,
                'expiresAt' => $entry->expiresAt,
                'lastAccessed' => $entry->lastAccessed,
            ];
        }
        return $data;
    }

    /**
     * Import cache data from persistence.
     *
     * @param array<TKey, array<string, mixed>> $data
     * @param bool $includeExpired Whether to include expired entries
     */
    public function import(array $data, bool $includeExpired = false): void
    {
        $now = time();
        foreach ($data as $key => $entryData) {
            $expiresAt = $entryData['expiresAt'] ?? ($now + $this->ttl);

            // Skip expired entries unless explicitly included
            if (!$includeExpired && $now >= $expiresAt) {
                continue;
            }

            $this->cache[$key] = new CacheEntry(
                value: $entryData['value'],
                fetchedAt: $entryData['fetchedAt'] ?? $now,
                expiresAt: $expiresAt,
                lastAccessed: $entryData['lastAccessed'] ?? $now
            );
        }
    }

    /**
     * Purge all stale entries.
     *
     * @return int Number of entries removed
     */
    public function purgeStale(): int
    {
        $removed = 0;
        foreach ($this->cache as $key => $entry) {
            if ($entry->isExpired()) {
                unset($this->cache[$key]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Evict entries if over capacity.
     */
    private function evictIfNeeded(): void
    {
        if (count($this->cache) <= $this->maxSize) {
            return;
        }

        // Remove expired entries first
        foreach ($this->cache as $key => $entry) {
            if ($entry->isExpired()) {
                unset($this->cache[$key]);
            }
        }

        // If still over capacity, remove least recently used
        while (count($this->cache) > $this->maxSize) {
            $this->evictOldest();
        }
    }

    /**
     * Evict the oldest (least recently accessed) entry.
     */
    private function evictOldest(): void
    {
        $lruKey = null;
        $lruTime = PHP_INT_MAX;

        foreach ($this->cache as $key => $entry) {
            if ($entry->lastAccessed < $lruTime) {
                $lruKey = $key;
                $lruTime = $entry->lastAccessed;
            }
        }

        if ($lruKey !== null) {
            unset($this->cache[$lruKey]);
        }
    }
}

/**
 * Specialized cache for flag states.
 */
class FlagCache extends Cache
{
    public function __construct(int $maxSize = 1000, int $ttl = 300)
    {
        parent::__construct($maxSize, $ttl);
    }

    /**
     * Set multiple flags at once.
     *
     * @param FlagState[] $flags
     */
    public function setAll(array $flags, ?int $customTtl = null): void
    {
        foreach ($flags as $flag) {
            $this->set($flag->key, $flag, $customTtl);
        }
    }

    /**
     * Get all valid flags.
     *
     * @return array<string, FlagState>
     */
    public function getAllFlags(): array
    {
        $result = [];
        foreach ($this->keys() as $key) {
            $value = $this->get($key);
            if ($value instanceof FlagState) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * @deprecated Use getAllFlags() instead
     * @return array<string, FlagState>
     */
    public function getAll(): array
    {
        return $this->getAllFlags();
    }

    /**
     * Get all flag keys.
     *
     * @return string[]
     */
    public function getAllFlagKeys(): array
    {
        return array_keys($this->getAllFlags());
    }

    /**
     * Check if a flag exists.
     */
    public function hasFlag(string $key): bool
    {
        return $this->has($key);
    }

    /**
     * Get a stale flag value for fallback.
     */
    public function getStaleFlag(string $key): ?FlagState
    {
        $value = $this->getStale($key);
        return $value instanceof FlagState ? $value : null;
    }
}
