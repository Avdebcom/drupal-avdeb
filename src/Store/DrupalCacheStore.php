<?php

declare(strict_types=1);

namespace Drupal\avdeb\Store;

use Drupal\avdeb\Lib\Store\StoreInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * The module's own cache bin (cache_avdeb): database, Redis or Memcache — whatever the site uses.
 *
 * Entries live for a week plus an hour (the "last good copy" window); freshness is decided by the
 * catalog client, not by the cache expiry.
 */
final class DrupalCacheStore implements StoreInterface {

  private const TTL = 7 * 24 * 3600 + 3600;

  public function __construct(private readonly CacheBackendInterface $cache) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $key): mixed {
    $item = $this->cache->get($key);
    return $item ? $item->data : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value): void {
    $this->cache->set($key, $value, time() + self::TTL);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    $this->cache->delete($key);
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    $this->cache->deleteAll();
  }

}
