<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Form;

use Drupal\bunny_net\CachePurger;
use Drupal\bunny_net\EdgeRule\EdgeRuleSync;
use Drupal\bunny_net\EdgeScript\ScriptDeployer;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\bunny_net\PullZone\PullZoneSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The pull zone, cache tag and purge settings.
 *
 * Pull zone settings, Edge Scripts and Edge Rules are deployed with Drush
 * from the exported bunny_net.settings and files in the site's repository,
 * so they are only shown here.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected ScriptDeployer $scriptDeployer,
    protected EdgeRuleSync $edgeRuleSync,
    protected QueueFactory $queueFactory,
    protected PullZoneSettings $pullZoneSettings,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('bunny_net.script_deployer'),
      $container->get('bunny_net.edge_rule_sync'),
      $container->get('queue'),
      $container->get('bunny_net.pull_zone_settings'),
    );
  }

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
    $form['purge']['queue'] = [
      '#type' => 'item',
      '#title' => $this->t('Queue'),
      '#markup' => $this->formatPlural(
        $this->queueFactory->get(CachePurger::QUEUE)->numberOfItems(),
        '1 purge waiting.',
        '@count purges waiting.',
      ),
      '#description' => $this->t('Cron works through the queue for up to 30 seconds per run. To work it through on its own, for example more often than cron runs or from a worker, run <code>drush queue:run @queue</code>; <code>--time-limit</code> caps how long it runs.', ['@queue' => CachePurger::QUEUE]),
    ];
    $form['purge']['concurrency'] = [
      '#type' => 'number',
      '#title' => $this->t('Purge requests sent at once'),
      '#min' => 1,
      '#max' => 50,
      '#description' => $this->t('bunny.net takes one tag or URL per request. The rate limits still apply: each request waits for its turn.'),
      '#config_target' => 'bunny_net.settings:purge.concurrency',
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

    $form['pull_zone_settings'] = $this->pullZoneSettings();
    $form['edge_scripts'] = $this->edgeScripts();
    $form['edge_rules'] = $this->edgeRules();

    return parent::buildForm($form, $form_state);
  }

  /**
   * The pull zone settings kept in config.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function pullZoneSettings(): array {
    $rows = [];
    foreach ($this->pullZoneSettings->configured() as $key => $value) {
      $rows[] = [$key, (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Pull zone settings'),
      '#open' => TRUE,
      '#description' => $this->t('Settings such as cookie vary, stale caching and origin retries, by their bunny.net API names, synced to the pull zone with <code>drush bunny:zone-sync</code>. Set them as <code>pull_zone_settings</code> in the exported <code>bunny_net.settings</code>; <code>drush bunny:zone-export</code> prints the current values. Settings left out are managed in the bunny.net dashboard.'),
      'settings' => [
        '#type' => 'table',
        '#header' => [$this->t('Setting'), $this->t('Value')],
        '#rows' => $rows,
        '#empty' => $this->t('None configured.'),
      ],
    ];
    try {
      $this->pullZoneSettings->values();
    }
    catch (\InvalidArgumentException $e) {
      $element['errors'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Not valid, so a sync would fail'),
        '#items' => explode("\n", $e->getMessage()),
      ];
    }
    return $element;
  }

  /**
   * The configured Edge Scripts, such as pull zone middleware.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function edgeScripts(): array {
    $rows = [];
    foreach ($this->scriptDeployer->overview() as $script) {
      $rows[] = [
        $script['name'],
        $script['script_id'] ?: $this->t('Missing'),
        $script['exists'] ? $script['file'] : $this->t('@file (not found)', ['@file' => $script['file'] ?: '-']),
        implode(', ', $script['variables']) ?: '-',
        implode(', ', $script['secrets']) ?: '-',
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Edge Scripts (middleware)'),
      '#open' => TRUE,
      '#description' => $this->t("Code that runs on bunny.net in front of the site, deployed from files in the repository with <code>drush bunny:script-deploy</code>. List them under <code>edge_scripts</code> in the exported <code>bunny_net.settings</code>, and put their secrets in settings.php as <code>\$settings['bunny_net']['edge_script_secrets']</code>."),
      'scripts' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Script ID'),
          $this->t('File'),
          $this->t('Variables'),
          $this->t('Secrets in settings.php'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('None configured.'),
      ],
    ];
  }

  /**
   * The Edge Rules directory and what it holds.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function edgeRules(): array {
    try {
      $directory = $this->edgeRuleSync->directory();
      try {
        $count = count($this->edgeRuleSync->read($directory));
        $status = $this->formatPlural($count, '@directory: 1 rule.', '@directory: @count rules.', ['@directory' => $directory]);
      }
      catch (\InvalidArgumentException $e) {
        $status = $e->getMessage();
      }
    }
    catch (NotConfiguredException) {
      $status = $this->t('No directory configured.');
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Edge Rules'),
      '#open' => TRUE,
      '#description' => $this->t('Rules bunny.net applies to requests, such as redirects and blocks, kept as YAML files in the repository and synced with <code>drush bunny:rules-sync</code>. Set the directory as <code>edge_rules.directory</code> in the exported <code>bunny_net.settings</code>.'),
      'status' => [
        '#type' => 'item',
        '#title' => $this->t('Rules'),
        '#markup' => $status,
      ],
    ];
  }

}
