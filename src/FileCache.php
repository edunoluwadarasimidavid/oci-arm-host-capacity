<?php

namespace Hitrov;

use Hitrov\Interfaces\CacheInterface;

class FileCache implements CacheInterface
{
    private const CACHE_FILENAME = 'oci_cache.json';

    private OciConfig $config;

    public function __construct(OciConfig $config)
    {
        $this->config = $config;

        $this->createCacheFile();
    }

    /**
     * @throws \JsonException
     */
    public function add($data, string $method): void
    {
        $cache = $this->getCache();
        $cacheKey = $this->getCacheKey($method);

        $cache[$method][$cacheKey] = $data;

        $encoded = json_encode($cache, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->getCacheFilename(), $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write OCI availability-domain cache.');
        }
    }

    /**
     * @return mixed
     * @throws \JsonException
     */
    public function get(string $method)
    {
        $cache = $this->getCache();
        if (!isset($cache[$method])) {
            return null;
        }
        $cacheKey = $this->getCacheKey($method);
        if (!isset($cache[$method][$cacheKey])) {
            return null;
        }

        return $cache[$method][$cacheKey];
    }

    public function getCacheKey(string $method): string
    {
        return md5(json_encode($this->config));
    }

    private function getCacheFilename(): string
    {
        return sprintf('%s/%s', getcwd(), self::CACHE_FILENAME);
    }

    /**
     * @throws \JsonException
     */
    private function getCache(): array
    {
        $cacheContents = @file_get_contents($this->getCacheFilename());
        if ($cacheContents === false || trim($cacheContents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($cacheContents, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException $e) {
            // A damaged cache must not prevent a fresh OCI availability lookup.
            return [];
        }
    }

    private function createCacheFile(): void
    {
        $filename = $this->getCacheFilename();
        if (file_exists($filename)) {
            return;
        }

        if (file_put_contents($filename, '{}', LOCK_EX) === false) {
            throw new \RuntimeException('Unable to create OCI availability-domain cache.');
        }
    }
}
