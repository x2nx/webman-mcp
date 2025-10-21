<?php

declare(strict_types=1);

namespace X2nx\WebmanMcp\Cache;

use Mcp\Server\NativeClock;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Uid\Uuid;
use support\Cache;

/**
 * Cache type enumeration for different MCP cache purposes
 */
enum CacheType: string
{
    case SESSION = 'session';
    case DISCOVERY = 'discovery';
    case GENERAL = 'general';
}

/**
 * Webman MCP Cache Implementation
 * 
 * Provides separate caching for MCP sessions and service discovery
 * with optimized performance and better error handling.
 */
class Webman implements SessionStoreInterface, CacheInterface
{
    /**
     * Cache key prefix for MCP sessions
     */
    private const SESSION_CACHE_PREFIX = 'mcp:session:';
    
    /**
     * Cache key prefix for MCP discovery
     */
    private const DISCOVERY_CACHE_PREFIX = 'mcp:discovery:';
    
    /**
     * Cache store name (empty for default)
     */
    private readonly string $store;
    
    /**
     * Default TTL in seconds
     */
    private readonly int $defaultTtl;
    
    /**
     * Clock for timestamp operations
     */
    private readonly ClockInterface $clock;
    
    /**
     * Cache instance (lazy loaded)
     */
    private ?CacheInterface $cacheInstance = null;
    
    /**
     * Cache type for this instance
     */
    private readonly CacheType $cacheType;

    public function __construct(
        string $store = '',
        int $defaultTtl = 3600,
        ?ClockInterface $clock = null,
        CacheType $cacheType = CacheType::GENERAL
    ) {
        $this->store = $store;
        $this->defaultTtl = $defaultTtl;
        $this->clock = $clock ?? new NativeClock();
        $this->cacheType = $cacheType;
    }

    // SessionStoreInterface methods - these always use session prefix
    public function exists(Uuid $id): bool
    {
        try {
            $key = $this->getSessionCacheKey($id);
            $data = $this->getCache()->get($key);
            
            return $data !== null && $data !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function read(Uuid $sessionId): string|false
    {
        try {
            $key = $this->getSessionCacheKey($sessionId);
            $data = $this->getCache()->get($key);
            
            if ($data === null || $data === false) {
                return false;
            }
            
            return $data;
        } catch (\Throwable) {
            return false;
        }
    }

    public function write(Uuid $sessionId, string $data): bool
    {
        try {
            $key = $this->getSessionCacheKey($sessionId);
            return $this->getCache()->set($key, $data, $this->defaultTtl);
        } catch (\Throwable) {
            return false;
        }
    }

    public function destroy(Uuid $sessionId): bool
    {
        try {
            $key = $this->getSessionCacheKey($sessionId);
            $this->getCache()->delete($key);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function gc(): array
    {
        return [];
    }

    // CacheInterface methods - these use appropriate prefix based on cache type
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $cacheKey = $this->getPrefixedKey($key);
            $value = $this->getCache()->get($cacheKey);
            
            if ($value === null || $value === false) {
                return $default;
            }
            
            return $value;
        } catch (\Throwable) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        try {
            $cacheKey = $this->getPrefixedKey($key);
            $ttlSeconds = $this->normalizeTtl($ttl);
            return $this->getCache()->set($cacheKey, $value, $ttlSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $cacheKey = $this->getPrefixedKey($key);
            return $this->getCache()->delete($cacheKey);
        } catch (\Throwable) {
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            return $this->getCache()->clear();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        
        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        try {
            $success = true;
            
            foreach ($values as $key => $value) {
                if (!$this->set($key, $value, $ttl)) {
                    $success = false;
                }
            }
            
            return $success;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteMultiple(iterable $keys): bool
    {
        try {
            $success = true;
            
            foreach ($keys as $key) {
                if (!$this->delete($key)) {
                    $success = false;
                }
            }
            
            return $success;
        } catch (\Throwable) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            $cacheKey = $this->getPrefixedKey($key);
            $value = $this->getCache()->get($cacheKey);
            return $value !== null && $value !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // Private helper methods
    private function getSessionCacheKey(Uuid $id): string
    {
        return self::SESSION_CACHE_PREFIX . $id->toRfc4122();
    }
    
    private function getDiscoveryCacheKey(string $key): string
    {
        return self::DISCOVERY_CACHE_PREFIX . $key;
    }
    
    private function getPrefixedKey(string $key): string
    {
        return match ($this->cacheType) {
            CacheType::DISCOVERY => $key, // MCP SDK already provides the correct prefix
            CacheType::SESSION => $key, // Session methods handle their own keys directly
            CacheType::GENERAL => $key,
        };
    }

    private function normalizeTtl(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }
        
        if (is_int($ttl)) {
            return $ttl;
        }
        
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTime();
            $future = (clone $now)->add($ttl);
            return $future->getTimestamp() - $now->getTimestamp();
        }
        
        return $this->defaultTtl;
    }

    private function getCache(): CacheInterface
    {
        if ($this->cacheInstance === null) {
            try {
                if (empty($this->store)) {
                    $this->cacheInstance = Cache::store();
                } else {
                    $this->cacheInstance = Cache::store($this->store);
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to create cache instance: ' . $e->getMessage(), 0, $e);
            }
        }
        
        return $this->cacheInstance;
    }

    // Factory methods
    public static function forSessions(string $store = '', int $ttl = 3600, ?ClockInterface $clock = null): self
    {
        return new self($store, $ttl, $clock, CacheType::SESSION);
    }

    public static function forDiscovery(string $store = '', int $ttl = 3600, ?ClockInterface $clock = null): self
    {
        return new self($store, $ttl, $clock, CacheType::DISCOVERY);
    }

    public static function create(string $store = '', int $ttl = 3600, ?ClockInterface $clock = null): self
    {
        return new self($store, $ttl, $clock, CacheType::GENERAL);
    }
    
    public static function fromConfig(array $config, CacheType $type = CacheType::GENERAL): self
    {
        $store = $config['store'] ?? '';
        $ttl = $config['ttl'] ?? 3600;
        
        return new self($store, $ttl, null, $type);
    }
}