<?php

namespace Drupal\avdeb\Lib;

use Drupal\avdeb\Lib\Store\StoreInterface;

/**
 * Cached AVDEB catalog client. Port of emdash-plugin-avdeb/src/client.ts.
 *
 * Every query is cached on its own. When AVDEB can't be reached the last good answer (kept for a
 * week) is served and retried after a back-off. Because any visitor's page view can trigger a
 * lookup, network calls are capped per minute so random queries can't burn the site's API quota.
 * Pure: the store and the HTTP call are injected.
 *
 * The HTTP callable: fn(string $url, array $headers, int $timeout): array{status: int, body: string}
 * — it may throw on network errors.
 */
final class Client {
  public const VERSION          = '1.0.0';
  public const STALE_SECONDS    = 7 * 24 * 3600;
  public const BACKOFF_SECONDS  = 15 * 60;
  public const EMPTY_BACKOFF    = 5 * 60;
  public const KEY_OK_SECONDS   = 10 * 60;
  public const KEY_FAIL_SECONDS = 5 * 60;

  private const K_BUDGET   = 'state.budget';
  private const K_STATS    = 'state.stats';
  private const P_PRODUCTS = 'p.';
  private const P_KEY      = 'k.';

  /**
   * @var callable */
  private $http;

  /**
   * @var callable */
  private $now;

  private string $identity;

  public function __construct(
    private StoreInterface $store,
    callable $http,
    private string $apiKey,
    private string $refCode,
    private int $cacheMinutes = 360,
    private int $budgetPerMinute = 30,
    ?callable $now = NULL,
    private int $timeout = 8,
  ) {
    $this->http         = $http;
    $this->now          = $now ?? 'time';
    $this->cacheMinutes = max(5, $cacheMinutes);
    $this->identity     = $apiKey !== '' ? 'k:' . self::hash($apiKey) : 'r:' . $refCode;
  }

  /**
   * FNV-1a (32-bit) as hex — same as the TypeScript client, for short stable keys. */
  public static function hash(string $input): string {
    $h = 0x811c9dc5;
    $n = \strlen($input);

    for ($i = 0; $i < $n; $i++) {
      $h ^= \ord($input[$i]);
      $h = ($h * 0x01000193) & 0xffffffff;
    }

    return str_pad(dechex($h), 8, '0', STR_PAD_LEFT);
  }

  private function t(): int {
    return (int) ($this->now)();
  }

  private function headers(): array {
    $h = ['Accept' => 'application/json', 'User-Agent' => 'drupal-avdeb/' . self::VERSION];

    if ($this->apiKey !== '') {
      $h['Authorization'] = 'Bearer ' . $this->apiKey;
    }

    return $h;
  }

  /**
   * @return array{ok: bool, json?: mixed, error?: string} */
  private function request(string $url): array {
    try {
      $res    = ($this->http)($url, $this->headers(), $this->timeout);
      $status = (int) ($res['status'] ?? 0);
      $json   = json_decode((string) ($res['body'] ?? ''), TRUE);

      return $status >= 200 && $status < 300
                ? ['ok' => TRUE, 'json' => $json]
                : ['ok' => FALSE, 'error' => Catalog::apiErrorMessage($status, $json)];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'error' => $e->getMessage() ?: \get_class($e)];
    }
  }

  /**
   * Approximate per-minute limiter (store round-trips aren't atomic; good enough as a quota guard). */
  private function takeBudget(): bool {
    $minute = intdiv($this->t(), 60);
    $cur    = $this->store->get(self::K_BUDGET);
    $count  = \is_array($cur) && ($cur['minute'] ?? NULL) === $minute ? (int) $cur['count'] : 0;

    if ($count >= $this->budgetPerMinute) {
      return FALSE;
    }

    $this->store->set(self::K_BUDGET, ['minute' => $minute, 'count' => $count + 1]);

    return TRUE;
  }

  private function record(?string $error): void {
    $prev = $this->store->get(self::K_STATS);
    $prev = \is_array($prev) ? $prev : ['requests' => 0, 'errors' => 0, 'lastFetchAt' => NULL, 'lastError' => NULL, 'lastErrorAt' => NULL];
    $t    = $this->t();
    $this->store->set(self::K_STATS, [
      'requests'    => $prev['requests'] + 1,
      'errors'      => $prev['errors'] + ($error !== NULL ? 1 : 0),
      'lastFetchAt' => $t,
      'lastError'   => $error ?? $prev['lastError'],
      'lastErrorAt' => $error !== NULL ? $t : $prev['lastErrorAt'],
    ]);
  }

  /**
   * @param array $query
   *   Catalog::sanitizeQuery() result.
   * @return array{products: array[], ref: string, tier: string, source: string, error?: string}
   */
  public function products(array $query): array {
    $key   = $this->identity . '|' . Catalog::queryKey($query);
    $id    = self::P_PRODUCTS . self::hash($key);
    $entry = $this->store->get($id);
    $entry = \is_array($entry) && ($entry['key'] ?? NULL) === $key ? $entry : NULL;
    $t     = $this->t();

    if ($entry && $t < $entry['freshUntil']) {
      return ['products' => $entry['products'], 'ref' => $entry['ref'], 'tier' => $entry['tier'], 'source' => 'cache'] + (isset($entry['error']) ? ['error' => $entry['error']] : []);
    }

    $fallbackTier = $this->apiKey !== '' ? 'secret' : 'anonymous';

    if (!$this->takeBudget()) {
      return $entry && $t < $entry['staleUntil']
                ? ['products' => $entry['products'], 'ref' => $entry['ref'], 'tier' => $entry['tier'], 'source' => 'stale', 'error' => 'rate limited']
                : ['products' => [], 'ref' => '', 'tier' => $fallbackTier, 'source' => 'empty', 'error' => 'rate limited'];
    }

    $res    = $this->request(Catalog::productsUrl($query, $this->apiKey !== '' ? '' : $this->refCode));
    $parsed = $res['ok'] ? Catalog::parseProductsResponse($res['json']) : NULL;
    $error  = $res['ok'] ? ($parsed ? NULL : 'Unexpected response from AVDEB') : $res['error'];
    $this->record($error);

    if ($parsed) {
      $ref = $parsed['ref'] !== '' ? $parsed['ref'] : ($this->apiKey !== '' ? '' : $this->refCode);
      $this->store->set($id, [
        'key'        => $key,
        'products'   => $parsed['products'],
        'ref'        => $ref,
        'tier'       => $parsed['tier'],
        'fetchedAt'  => $t,
        'freshUntil' => $t + $this->cacheMinutes * 60,
        'staleUntil' => $t + self::STALE_SECONDS,
      ]);

      return ['products' => $parsed['products'], 'ref' => $ref, 'tier' => $parsed['tier'], 'source' => 'network'];
    }

    if ($entry && $t < $entry['staleUntil']) {
      $this->store->set($id, ['freshUntil' => $t + self::BACKOFF_SECONDS, 'error' => $error] + $entry);

      return ['products' => $entry['products'], 'ref' => $entry['ref'], 'tier' => $entry['tier'], 'source' => 'stale', 'error' => $error];
    }

    $this->store->set($id, [
      'key'        => $key,
      'products'   => [],
      'ref'        => '',
      'tier'       => $fallbackTier,
      'fetchedAt'  => $t,
      'freshUntil' => $t + self::EMPTY_BACKOFF,
      'staleUntil' => $t + self::EMPTY_BACKOFF,
      'error'      => $error,
    ]);

    return ['products' => [], 'ref' => '', 'tier' => $fallbackTier, 'source' => 'empty', 'error' => $error];
  }

  /**
   * Who the secret key belongs to. null without a key.
   *
   * @return array{ok: true, owner: array}|array{ok: false, error: string}|null
   */
  public function keyInfo(): ?array {
    if ($this->apiKey === '') {
      return NULL;
    }

    $id     = self::P_KEY . self::hash($this->apiKey);
    $cached = $this->store->get($id);
    $t      = $this->t();

    if (\is_array($cached) && isset($cached['until']) && $t < $cached['until']) {
      return $cached['info'];
    }

    $res   = $this->request(Catalog::API_BASE . '/key');
    $owner = $res['ok'] ? Catalog::parseKeyResponse($res['json']) : NULL;
    $info  = $owner
            ? ['ok' => TRUE, 'owner' => $owner]
            : ['ok' => FALSE, 'error' => $res['ok'] ? 'Unexpected response from AVDEB' : $res['error']];
    $this->record($info['ok'] ? NULL : $info['error']);
    $this->store->set($id, ['info' => $info, 'until' => $t + ($info['ok'] ? self::KEY_OK_SECONDS : self::KEY_FAIL_SECONDS)]);

    return $info;
  }

  /**
   * The referral code links carry: the key owner's with a key, else the manual code. */
  public function ref(): string {
    if ($this->apiKey === '') {
      return $this->refCode;
    }

    $info = $this->keyInfo();

    return $info && $info['ok'] ? $info['owner']['ref'] : '';
  }

  /**
   * "Refresh products now": drop every cached query and the key check (stats survive). */
  public function flush(): void {
    $stats = $this->store->get(self::K_STATS);
    $this->store->clear();

    if ($stats !== NULL) {
      $this->store->set(self::K_STATS, $stats);
    }
  }

  /**
   * @return array{requests: int, errors: int, lastFetchAt: ?int, lastError: ?string, lastErrorAt: ?int} */
  public function stats(): array {
    $s = $this->store->get(self::K_STATS);

    return \is_array($s) ? $s : ['requests' => 0, 'errors' => 0, 'lastFetchAt' => NULL, 'lastError' => NULL, 'lastErrorAt' => NULL];
  }

}
