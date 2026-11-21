<?php

declare(strict_types=1);

namespace Drupal\avdeb\Plugin\Block;

use Drupal\avdeb\Embeds;
use Drupal\avdeb\Lib\Options;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AVDEB products in any region: sidebar, footer, home page.
 */
#[Block(
  id: 'avdeb_products',
  admin_label: new TranslatableMarkup('AVDEB Products'),
  category: new TranslatableMarkup('AVDEB'),
)]
final class AvdebProductsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
  public function defaultConfiguration(): array {
    return [
      'link' => '',
      'layout' => '',
      'limit' => 6,
      'columns' => 0,
      'heading' => '',
      'card_style' => '',
      'theme' => '',
    ] + parent::defaultConfiguration();
  }

  private static function choices(array $values, array $labels = []): array {
    $out = ['' => new TranslatableMarkup('Site default')];
    foreach ($values as $v) {
      $out[$v] = $labels[$v] ?? ucfirst($v);
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $c = $this->configuration;
    $form['link'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AVDEB link or search'),
      '#description' => $this->t('A creator, category or product link (e.g. https://avdeb.com/category/stickers/) or search words. Empty: newest products.'),
      '#default_value' => $c['link'],
      '#maxlength' => 300,
    ];
    $form['layout'] = [
      '#type' => 'select',
      '#title' => $this->t('Layout'),
      '#options' => self::choices(Options::LAYOUTS),
      '#default_value' => $c['layout'],
    ];
    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Products'),
      '#min' => 1,
      '#max' => 48,
      '#default_value' => $c['limit'],
      '#description' => $this->t('Up to 12 without a secret key, 48 with one.'),
    ];
    $form['columns'] = [
      '#type' => 'number',
      '#title' => $this->t('Columns'),
      '#min' => 0,
      '#max' => 6,
      '#default_value' => $c['columns'],
      '#description' => $this->t('0 = site default. Narrow regions get fewer columns automatically.'),
    ];
    $form['heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Heading inside the embed'),
      '#default_value' => $c['heading'],
      '#maxlength' => 120,
    ];
    $form['card_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Card style'),
      '#options' => self::choices(Options::CARD_STYLES),
      '#default_value' => $c['card_style'],
    ];
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Colour theme'),
      '#options' => self::choices(Options::THEMES, ['site' => $this->t('Inherit from theme'), 'auto' => $this->t("Follow visitor's system setting")]),
      '#default_value' => $c['theme'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    foreach (['link', 'layout', 'heading', 'card_style', 'theme'] as $key) {
      $this->configuration[$key] = trim((string) $form_state->getValue($key));
    }
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
    $this->configuration['columns'] = (int) $form_state->getValue('columns');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $c = $this->configuration;
    $attrs = ['link' => $c['link'], 'limit' => (string) $c['limit'], 'heading' => $c['heading']];
    foreach (['layout' => 'layout', 'card_style' => 'cardStyle', 'theme' => 'theme'] as $key => $attr) {
      if ($c[$key] !== '') {
        $attrs[$attr] = $c[$key];
      }
    }
    if ((int) $c['columns'] > 0) {
      $attrs['columns'] = (string) $c['columns'];
    }
    if (trim($c['link']) === '') {
      $attrs['source'] = 'newest';
    }
    $level = $this->label() && ($c['label_display'] ?? '') ? 3 : 2;
    // Renderer escapes every value itself; Markup keeps its inline SVG and CSS custom properties.
    return ['#markup' => Markup::create($this->embeds->render($attrs, $level))] + $this->embeds->attachments();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->embeds->maxAge();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return array_merge(parent::getCacheTags(), [Embeds::CACHE_TAG, 'config:avdeb.settings']);
  }

}
