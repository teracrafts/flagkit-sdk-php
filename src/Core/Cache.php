<?php

declare(strict_types=1);

namespace FlagKit\Core;

use FlagKit\Types\FlagState;

class CacheEntry
{
    public function __construct(
        public readonly mixed $value,
        public readonly int $expiresAt,
        public int $lastAccessed
    ) {
    }
}

/**
 * @template TKey of string
 * @template TValue
 */
class Cache
{
    /** @var array<TKey, CacheEntry> */
    private array $cache = [];

    public function __construct(
        private readonly int $maxSize = 1000,
        private readonly int $ttl = 300
    ) {
    }

    /**
     * @param TKey $key
     * @return TValue|null
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        $entry = $this->cache[$key];
        if (time() >= $entry->expiresAt) {
            unset($this->cache[$key]);
            return null;
        }

        $entry->lastAccessed = time();
        return $entry->value;
    }

    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function set(string $key, mixed $value, ?int $customTtl = null): void
    {
        $ttl = $customTtl ?? $this->ttl;
        $now = time();

        $this->cache[$key] = new CacheEntry(
            value: $value,
            expiresAt: $now + $ttl,
            lastAccessed: $now
        );

        $this->evictIfNeeded();
    }

    /**
     * @param TKey $key
     */
    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        if (time() >= $this->cache[$key]->expiresAt) {
            unset($this->cache[$key]);
            return false;
        }

        return true;
    }

    /**
     * @param TKey $key
     */
    public function remove(string $key): bool
    {
        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            return true;
        }
        return false;
    }

    public function clear(): void
    {
        $this->cache = [];
    }

    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * @return array<TKey>
     */
    public function keys(): array
    {
        return array_keys($this->cache);
    }

    private function evictIfNeeded(): void
    {
        if (count($this->cache) <= $this->maxSize) {
            return;
        }

        // Remove expired entries first
        $now = time();
        foreach ($this->cache as $key => $entry) {
            if ($now >= $entry->expiresAt) {
                unset($this->cache[$key]);
            }
        }

        // If still over capacity, remove least recently used
        while (count($this->cache) > $this->maxSize) {
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
            } else {
                break;
            }
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
     * @param FlagState[] $flags
     */
    public function setAll(array $flags): void
    {
        foreach ($flags as $flag) {
            $this->set($flag->key, $flag);
        }
    }

    /**
     * @return array<string, FlagState>
     */
    public function getAll(): array
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
}
