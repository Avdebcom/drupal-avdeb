<?php

namespace Drupal\avdeb\Lib;

/**
 * Site-wide settings, stored as the "Content - AVDEB Products" plugin's parameters (Extensions →
 * Plugins). The module and editor button read the same settings. Pure.
 */
final class Settings {
  public const DEFAULT_DISCLOSURE = 'As an AVDEB affiliate, I earn a commission from qualifying purchases.';

  /**
   * @return array{apiKey: string, rawApiKey: string, keyProblem: ?string, refCode: string,
   *   cacheMinutes: int, showDisclosure: bool, disclosureText: string, display: array}
   */
  public static function fromParams(array $params): array {
    $rawKey     = trim(Catalog::str($params['api_key'] ?? ''));
    $disclosure = Options::text($params['disclosure_text'] ?? '', 300, self::DEFAULT_DISCLOSURE);

    return [
      'apiKey'         => Catalog::usableKey($rawKey),
      'rawApiKey'      => $rawKey,
      'keyProblem'     => Catalog::keyProblem($rawKey),
      'refCode'        => Catalog::cleanRef($params['ref_code'] ?? ''),
      'cacheMinutes'   => Options::int($params['cache_minutes'] ?? NULL, 5, 2880, 360),
      'showDisclosure' => Options::bool($params['show_disclosure'] ?? NULL, TRUE),
      'disclosureText' => $disclosure,
      'display'        => Options::display($params),
    ];
  }

  /**
   * Disclosure shown under an embed — only when its links actually carry a referral code. */
  public static function disclosureFor(array $settings, string $ref): ?string {
    return $ref !== '' && $settings['showDisclosure'] ? $settings['disclosureText'] : NULL;
  }

  /**
   * `avdeb_sk_…a1b2` for the admin screen; the key itself is never rendered. */
  public static function maskKey(string $key): string {
    return $key === '' ? '' : substr($key, 0, 9) . '…' . substr($key, -4);
  }

}
