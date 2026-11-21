<?php

declare(strict_types=1);

namespace Drupal\avdeb\Plugin\Filter;

use Drupal\avdeb\Embeds;
use Drupal\avdeb\Lib\Shortcodes;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Replaces {avdeb-products …}, {avdeb-product …} and {avdeb-button …} tags with product embeds.
 *
 * Runs late (after "Limit allowed HTML tags"), so the embed markup is never stripped; the tags
 * themselves are plain text, so every editor and text format can hold them.
 */
#[Filter(
  id: 'avdeb_embeds',
  title: new TranslatableMarkup('AVDEB product tags'),
  description: new TranslatableMarkup('Turns {avdeb-products …}, {avdeb-product …} and {avdeb-button …} into AVDEB product embeds with your referral code.'),
  type: FilterInterface::TYPE_MARKUP_LANGUAGE,
  weight: 100,
)]
final class AvdebFilter extends FilterBase implements ContainerFactoryPluginInterface {

  private Embeds $embeds;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->embeds = $container->get('avdeb.embeds');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($text);
    if (!Shortcodes::contains($text)) {
      return $result;
    }

    $embeds = $this->embeds;
    $html = Shortcodes::replace($text, static function (string $tag, array $attrs) use ($embeds): string {
      try {
        if ($tag === 'button') {
          return $embeds->button($attrs);
        }
        if ($tag === 'product') {
          $attrs['mode'] = 'product';
        }
        $level = (int) ($attrs['heading-level'] ?? $attrs['heading_level'] ?? 2);
        return $embeds->render($attrs, $level ?: 2);
      }
      catch (\Throwable $e) {
        // Never break a page because of an embed.
        return '<!-- AVDEB Products: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
      }
    });

    $attachments = $embeds->attachments();
    $result->setProcessedText($html);
    $result->addAttachments($attachments['#attached']);
    $result->addCacheTags($attachments['#cache']['tags']);
    $result->mergeCacheMaxAge($attachments['#cache']['max-age']);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE): string {
    if (!$long) {
      return (string) $this->t('Show AVDEB products with {avdeb-products https://avdeb.com/category/stickers/ layout="carousel"}, one product with {avdeb-product https://avdeb.com/store/…/}, or a button with {avdeb-button href="/creators/…/" label="Visit my shop"}.');
    }
    return (string) $this->t('<p>AVDEB product tags:</p>
<ul>
<li><code>{avdeb-products https://avdeb.com/category/stickers/ layout="carousel" limit="8" heading="Stickers"}</code>: products from a creator, category or search. Layouts: grid, carousel, list, spotlight.</li>
<li><code>{avdeb-product https://avdeb.com/store/cat-sticker/ variant="hero" badge="Editor\'s pick" note="Why I like it"}</code>: one featured product (variants: horizontal, hero, card).</li>
<li><code>{avdeb-button href="/creators/cleo/" label="Visit my shop" variant="outline" align="center"}</code>: a referral button.</li>
<li>Write <code>{{avdeb-products}}</code> to show a tag as text.</li>
</ul>');
  }

}
