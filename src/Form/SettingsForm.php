<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;

/**
 * The pull zone, cache tag and purge settings.
 *
 * Edge Scripts are configured in bunny_net.settings directly; their secrets
 * belong in settings.php, so this form has no fields for them.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'bunny_net_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The config names.
   */
  protected function getEditableConfigNames(): array {
    return ['bunny_net.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = Settings::get('bunny_net', []);
    $hasKey = is_array($settings) && is_string($settings['api_key'] ?? NULL) && trim($settings['api_key']) !== '';
    $form['api_key'] = [
      '#type' => 'item',
      '#title' => $this->t('API key'),
      '#markup' => $hasKey ? $this->t('Set in settings.php.') : $this->t("Not set. Add <code>\$settings['bunny_net']['api_key']</code> to settings.php."),
    ];
    $form['pull_zone_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Pull zone ID'),
      '#min' => 0,
      '#description' => $this->t("The number in the pull zone's address in the bunny.net dashboard."),
      '#config_target' => 'bunny_net.settings:pull_zone_id',
    ];

    $form['purge'] = [
      '#type' => 'details',
      '#title' => $this->t('Purging'),
      '#open' => TRUE,
    ];
    $form['purge']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Purge when Drupal invalidates cache tags'),
      '#description' => $this->t("Turn this on per environment in settings.php, so copies of production cannot purge it: <code>\$config['bunny_net.settings']['purge']['enabled'] = TRUE;</code>"),
      '#config_target' => 'bunny_net.settings:purge.enabled',
    ];
    $form['purge']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('When to purge'),
      '#options' => [
        'terminate' => $this->t('At the end of the request, after the response is sent; what the rate limits defer goes to the queue'),
        'queue' => $this->t('Queue everything for cron'),
      ],
      '#config_target' => 'bunny_net.settings:purge.mode',
    ];
    $form['purge']['on_cache_rebuild'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Purge everything on a cache rebuild'),
      '#description' => $this->t('For example on <code>drush cr</code> at the end of a deploy.'),
      '#config_target' => 'bunny_net.settings:purge.on_cache_rebuild',
    ];

    $form['tags'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache tags'),
      '#open' => TRUE,
    ];
    $form['tags']['header'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add a CDN-Tag header to responses'),
      '#description' => $this->t('Without it, purging by tag reaches nothing.'),
      '#config_target' => 'bunny_net.settings:tags.header',
    ];
    $form['tags']['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tag prefix'),
      '#description' => $this->t('Needed when several sites share the pull zone, so they purge only their own pages. Use a different one per site.'),
      '#config_target' => 'bunny_net.settings:tags.prefix',
    ];
    $form['tags']['purifier'] = [
      '#type' => 'item',
      '#markup' => $this->t('Which tags are written and purged is set in Cache Purifier: its blocklist, aliases and buckets, and its rules for who and what may purge.'),
    ];

    $form['edge_rules'] = [
      '#type' => 'details',
      '#title' => $this->t('Edge Rules'),
      '#open' => TRUE,
    ];
    $form['edge_rules']['directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rules directory'),
      '#description' => $this->t('YAML rule files, relative to the Drupal root, e.g. <code>../bunny/rules</code>. Sync them with <code>drush bunny:rules-sync</code>.'),
      '#config_target' => 'bunny_net.settings:edge_rules.directory',
    ];

    return parent::buildForm($form, $form_state);
  }

}
