<?php

declare(strict_types=1);

namespace Drupal\avdeb;

use Drupal\avdeb\Lib\Catalog;
use Drupal\avdeb\Lib\Client;
use Drupal\avdeb\Lib\Options;
use Drupal\avdeb\Lib\Renderer;
use Drupal\avdeb\Lib\Settings;
use Drupal\avdeb\Store\DrupalCacheStore;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The one service the text filter, the block and the settings form use: settings, a cached catalog
 * client and rendering. Identical embeds on one page share a single lookup.
 */
final class Embeds {

  /**
   * Cache tag on every rendered embed; invalidated by "Refresh products now" and on settings save. */
  public const CACHE_TAG = 'avdeb_products';

  private ?array $settings = NULL;

  private ?Client $client = NULL;

  /**
   * @var array<string, array> */
  private array $memo = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly ClientInterface $httpClient,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
  ) {}

  public function settings(): array {
    return $this->settings ??= Settings::fromParams($this->configFactory->get('avdeb.settings')->getRawData());
  }

  public function client(): Client {
    if ($this->client) {
      return $this->client;
    }
    $http = function (string $url, array $headers, int $timeout): array {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => $headers,
        'timeout' => $timeout,
        'http_errors' => FALSE,
      ]);
      return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    };
    $s = $this->settings();
    return $this->client = new Client(new DrupalCacheStore($this->cache), $http, $s['apiKey'], $s['refCode'], $s['cacheMinutes']);
  }

  public function locale(): string {
    return $this->languageManager->getCurrentLanguage()->getId() ?: 'en';
  }

  /**
   * Products for a query, de-duplicated within the request. */
  public function lookup(array $query): array {
    $key = Catalog::queryKey($query);
    if (!isset($this->memo[$key])) {
      try {
        $this->memo[$key] = $this->client()->products($query);
      }
      catch (\Throwable $e) {
        $this->logger->warning('AVDEB catalog request failed: @message', ['@message' => $e->getMessage()]);
        $this->memo[$key] = ['products' => [], 'ref' => '', 'tier' => 'anonymous', 'source' => 'empty'];
      }
    }
    return $this->memo[$key];
  }

  /**
   * HTML for {avdeb-products} / {avdeb-product} tags and the block. */
  public function render(array $attrs, int $headingLevel = 2): string {
    $settings = $this->settings();
    $o = Options::parse($attrs, $settings['display']);
    if ($o['mode'] === 'product' && Options::productHandle($o['value']) === '') {
      return '<!-- AVDEB Product: add a product link, e.g. {avdeb-product https://avdeb.com/store/cat-sticker/} -->';
    }
    $query = Catalog::sanitizeQuery(Options::queryFor($o), Catalog::maxLimit($settings['apiKey'] !== ''));
    $out = $this->lookup($query);
    return Renderer::embed([
      'products' => $out['products'],
      'ref' => $out['ref'],
      'query' => $query,
      'disclosure' => Settings::disclosureFor($settings, $out['ref']),
    ], $o, $this->locale(), $headingLevel);
  }

  /**
   * HTML for {avdeb-button} tags. */
  public function button(array $attrs): string {
    try {
      $ref = $this->client()->ref();
    }
    catch (\Throwable) {
      $ref = $this->settings()['refCode'];
    }
    return Renderer::button(Options::button($attrs), $ref, $this->settings()['display']);
  }

  /**
   * Seconds a rendered embed may be cached (render cache, dynamic page cache). */
  public function maxAge(): int {
    return $this->settings()['cacheMinutes'] * 60;
  }

  /**
   * Cacheability + library every render array with an embed needs. */
  public function attachments(): array {
    return [
      '#attached' => ['library' => ['avdeb/embed']],
      '#cache' => ['tags' => [self::CACHE_TAG, 'config:avdeb.settings'], 'max-age' => $this->maxAge()],
    ];
  }

  /**
   * "Refresh products now": drop cached products and re-render every embed. */
  public function refresh(): void {
    $this->client()->flush();
    $this->memo = [];
    Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Everything the settings page status panel shows. Never contains the secret key. */
  public function status(): array {
    $settings = $this->settings();
    $client = $this->client();
    $key = $client->keyInfo();
    $creator = $key && $key['ok'] ? $key['owner']['creatorSlug'] : '';
    return [
      'hasKey' => $settings['rawApiKey'] !== '',
      'maskedKey' => Settings::maskKey($settings['rawApiKey']),
      'keyProblem' => $settings['keyProblem'],
      'key' => $key,
      'ref' => $client->ref(),
      'stats' => $client->stats(),
      'creator' => $creator,
    ];
  }

}
