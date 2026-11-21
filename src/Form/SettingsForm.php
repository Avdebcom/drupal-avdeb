<?php

declare(strict_types=1);

namespace Drupal\avdeb\Form;

use Drupal\avdeb\Embeds;
use Drupal\avdeb\Lib\Catalog;
use Drupal\avdeb\Lib\Options;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration → Content authoring → AVDEB Products.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The AVDEB embeds service.
   */
  private Embeds $embeds;

  /**
   * The date formatter.
   */
  private DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->embeds = $container->get('avdeb.embeds');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'avdeb_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['avdeb.settings'];
  }

  private static function options(array $values, array $labels = []): array {
    $out = [];
    foreach ($values as $v) {
      $out[$v] = $labels[$v] ?? ucfirst($v);
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('avdeb.settings');
    $form['#attached']['library'][] = 'avdeb/embed';

    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status'),
      '#markup' => $this->statusMarkup(),
    ];

    $form['account'] = [
      '#type' => 'details',
      '#title' => $this->t('Your AVDEB account'),
      '#open' => TRUE,
      '#description' => $this->t('No account yet? <a href=":apply" target="_blank" rel="noopener">Join the AVDEB affiliate program</a> (free, 5% commission, 30-day cookie), then copy your code from the <a href=":dash" target="_blank" rel="noopener">partner dashboard</a>.', [
        ':apply' => 'https://avdeb.com/affiliate/apply/',
        ':dash' => 'https://avdeb.com/affiliate/dashboard/developers/',
      ]),
    ];
    $form['account']['ref_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Referral code'),
      '#default_value' => $config->get('ref_code'),
      '#maxlength' => 40,
      '#description' => $this->t('Added as ?ref= to every AVDEB link. Not needed with a secret key.'),
    ];
    $hasKey = (string) $config->get('api_key') !== '';
    $form['account']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret key (optional)'),
      '#description' => $hasKey
        ? $this->t('A key is saved. Leave empty to keep it.')
        : $this->t('avdeb_sk_… from Partner dashboard → Embeds &amp; API. Adds your referral code automatically and allows up to 48 products per embed.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];
    if ($hasKey) {
      $form['account']['remove_key'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the saved secret key'),
      ];
    }

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display defaults'),
      '#open' => TRUE,
      '#description' => $this->t('Every tag and block can override these, e.g. {avdeb-products … layout="list" theme="dark"}.'),
    ];
    $form['display']['layout'] = [
      '#type' => 'select',
      '#title' => $this->t('Default layout'),
      '#options' => self::options(Options::LAYOUTS, ['spotlight' => $this->t('Spotlight (first product featured)')]),
      '#default_value' => $config->get('layout'),
    ];
    $form['display']['columns'] = [
      '#type' => 'number',
      '#title' => $this->t('Default columns'),
      '#min' => 1,
      '#max' => 6,
      '#default_value' => $config->get('columns'),
    ];
    $form['display']['card_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Card style'),
      '#options' => self::options(Options::CARD_STYLES, ['elevated' => $this->t('Elevated (soft shadow)')]),
      '#default_value' => $config->get('card_style'),
    ];
    $form['display']['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Colour theme'),
      '#options' => self::options(Options::THEMES, ['site' => $this->t('Inherit from theme'), 'auto' => $this->t("Follow visitor's system setting")]),
      '#default_value' => $config->get('theme'),
    ];
    $form['display']['accent_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Accent colour'),
      '#default_value' => $config->get('accent_color'),
      '#size' => 10,
      '#maxlength' => 9,
      '#placeholder' => '#3b6548',
      '#description' => $this->t('Hex colour for prices, badges and buttons. Empty: AVDEB green.'),
    ];
    $form['display']['radius'] = [
      '#type' => 'number',
      '#title' => $this->t('Corner radius (px)'),
      '#min' => 0,
      '#max' => 32,
      '#default_value' => $config->get('radius'),
    ];
    $form['display']['image_ratio'] = [
      '#type' => 'select',
      '#title' => $this->t('Image shape'),
      '#options' => ['square' => $this->t('Square (1:1)'), 'portrait' => $this->t('Portrait (4:5)'), 'landscape' => $this->t('Landscape (4:3)')],
      '#default_value' => $config->get('image_ratio'),
    ];
    $form['display']['cta_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button text'),
      '#default_value' => $config->get('cta_label'),
      '#maxlength' => 40,
    ];
    foreach ([
      'show_price' => $this->t('Show prices'),
      'show_creator' => $this->t('Show designer name'),
      'show_badges' => $this->t('Show Sale / New badges'),
      'new_tab' => $this->t('Open product links in a new tab'),
    ] as $key => $label) {
      $form['display'][$key] = ['#type' => 'checkbox', '#title' => $label, '#default_value' => $config->get($key)];
    }

    $form['disclosure'] = [
      '#type' => 'details',
      '#title' => $this->t('Affiliate disclosure'),
      '#open' => FALSE,
    ];
    $form['disclosure']['show_disclosure'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show a disclosure under embeds whose links carry a referral code'),
      '#description' => $this->t('Required in many countries, e.g. by the US FTC.'),
      '#default_value' => $config->get('show_disclosure'),
    ];
    $form['disclosure']['disclosure_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Disclosure text'),
      '#default_value' => $config->get('disclosure_text'),
      '#maxlength' => 300,
    ];

    $form['cache'] = [
      '#type' => 'details',
      '#title' => $this->t('Caching'),
      '#open' => FALSE,
    ];
    $form['cache']['cache_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Refresh products every (minutes)'),
      '#min' => 5,
      '#max' => 2880,
      '#default_value' => $config->get('cache_minutes'),
      '#description' => $this->t('Products are cached on your site; the last good copy is kept for a week if AVDEB is unreachable.'),
    ];
    $form['cache']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh products now'),
      '#submit' => ['::refreshProducts'],
      '#limit_validation_errors' => [],
    ];

    $form['usage'] = [
      '#type' => 'details',
      '#title' => $this->t('How to add products'),
      '#open' => FALSE,
      '#markup' => '<p>' . $this->t('In any body field using Basic HTML or Full HTML:') . '</p><ul>'
        . '<li><code>{avdeb-products https://avdeb.com/category/stickers/ layout="carousel"}</code></li>'
        . '<li><code>{avdeb-product https://avdeb.com/store/cat-sticker/ variant="hero" badge="Editor\'s pick"}</code></li>'
        . '<li><code>{avdeb-button href="/creators/cleo/" label="Visit my shop"}</code></li></ul><p>'
        . $this->t('Or place the <em>AVDEB Products</em> block under Structure → Block layout.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  private function statusMarkup(): Markup {
    try {
      $s = $this->embeds->status();
    }
    catch (\Throwable $e) {
      return Markup::create('<p>' . $this->t('Could not reach AVDEB: @error', ['@error' => $e->getMessage()]) . '</p>');
    }
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    if ($s['key'] && $s['key']['ok']) {
      $line = $this->t('Connected as <strong>@name</strong>. Links carry referral code <code>@ref</code>.', ['@name' => $s['key']['owner']['name'], '@ref' => $s['ref']]);
      $tone = 'status';
    }
    elseif ($s['hasKey']) {
      $line = $s['keyProblem'] === 'publishable'
        ? $this->t('This is a publishable key (avdeb_pk_…). Use the secret key (avdeb_sk_…).')
        : $this->t('The secret key was not accepted: @error', ['@error' => $s['key']['error'] ?? $this->t('malformed key')]);
      $tone = 'error';
    }
    elseif ($s['ref'] !== '') {
      $line = $this->t('Links carry referral code <code>@ref</code>.', ['@ref' => $s['ref']]);
      $tone = 'status';
    }
    else {
      $line = $this->t("No referral code yet: product links work, but sales aren't credited to you.");
      $tone = 'warning';
    }
    $stats = $s['stats'];
    $last = $stats['lastFetchAt'] ? $this->t('@n requests, last @ago ago', ['@n' => $stats['requests'], '@ago' => $this->dateFormatter->formatDiff($stats['lastFetchAt'], time())]) : $this->t('No requests yet');
    $error = $stats['lastError'] ? ' · ' . $this->t('last error: @e', ['@e' => $stats['lastError']]) : '';
    return Markup::create('<div class="messages messages--' . $tone . '">' . $line . '</div><p><small>' . $e((string) $last) . $e($error) . '</small></p>');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $key = trim((string) $form_state->getValue('api_key'));
    if ($key !== '') {
      $problem = Catalog::keyProblem($key);
      if ($problem === 'publishable') {
        $form_state->setErrorByName('api_key', $this->t('That is a publishable key (avdeb_pk_…). Paste the secret key (avdeb_sk_…).'));
      }
      elseif ($problem !== NULL) {
        $form_state->setErrorByName('api_key', $this->t('A secret key looks like avdeb_sk_ followed by 32 letters and digits.'));
      }
    }
    $color = trim((string) $form_state->getValue('accent_color'));
    if ($color !== '' && Options::safeColor($color) === '') {
      $form_state->setErrorByName('accent_color', $this->t('Use a hex colour such as #3b6548.'));
    }
    $ref = trim((string) $form_state->getValue('ref_code'));
    if ($ref !== '' && Catalog::cleanRef($ref) !== $ref) {
      $form_state->setErrorByName('ref_code', $this->t('Referral codes contain only letters, digits, "-" and "_".'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('avdeb.settings');
    $key = trim((string) $form_state->getValue('api_key'));
    if ($form_state->getValue('remove_key')) {
      $config->set('api_key', '');
    }
    elseif ($key !== '') {
      $config->set('api_key', $key);
    }
    foreach (['ref_code', 'layout', 'card_style', 'theme', 'accent_color', 'image_ratio', 'cta_label', 'disclosure_text'] as $k) {
      $config->set($k, trim((string) $form_state->getValue($k)));
    }
    foreach (['columns', 'radius', 'cache_minutes'] as $k) {
      $config->set($k, (int) $form_state->getValue($k));
    }
    foreach (['show_price', 'show_creator', 'show_badges', 'new_tab', 'show_disclosure'] as $k) {
      $config->set($k, (bool) $form_state->getValue($k));
    }
    $config->save();
    Cache::invalidateTags([Embeds::CACHE_TAG]);
    parent::submitForm($form, $form_state);
  }

  public function refreshProducts(array &$form, FormStateInterface $form_state): void {
    $this->embeds->refresh();
    $this->messenger()->addStatus($this->t('Products refreshed. Pages show the latest products on their next view.'));
  }

}
