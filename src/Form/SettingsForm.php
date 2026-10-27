<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Form;

use Drupal\bunny_net\CachePurger;
use Drupal\bunny_net\EdgeRule\EdgeRuleSync;
use Drupal\bunny_net\EdgeScript\ScriptDeployer;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\bunny_net\PullZone\CachingSync;
use Drupal\bunny_net\PullZone\PullZoneSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Valicm\BunnyToolkit\Caching\CachingChoices;
use Valicm\BunnyToolkit\Caching\VaryCache;
use Valicm\BunnyToolkit\Http\ApiException;

/**
 * The pull zone, cache tag and purge settings.
 *
 * Pull zone settings, Edge Scripts and Edge Rules are deployed with Drush
 * from the exported bunny_net.settings and files in the site's repository,
 * so they are only shown here. Caching is set here, and written to the pull
 * zone with Drush or the form's own button.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected ScriptDeployer $scriptDeployer,
    protected EdgeRuleSync $edgeRuleSync,
    protected QueueFactory $queueFactory,
    protected PullZoneSettings $pullZoneSettings,
    protected CachingSync $cachingSync,
    protected CountryManagerInterface $countryManager,
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
      $container->get('bunny_net.caching_sync'),
      $container->get('country_manager'),
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

    $form['caching'] = $this->caching();
    $form['pull_zone_settings'] = $this->pullZoneSettings();
    $form['edge_scripts'] = $this->edgeScripts();
    $form['edge_rules'] = $this->edgeRules();

    $form = parent::buildForm($form, $form_state);
    $form['actions']['apply_caching'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and apply caching to the pull zone'),
      '#submit' => ['::submitForm', '::applyCaching'],
      '#states' => ['visible' => [':input[name="caching[enabled]"]' => ['checked' => TRUE]]],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $values = $form_state->getValue('caching');
    if (!is_array($values) || empty($values['enabled'])) {
      return;
    }
    $lists = [];
    foreach (['vary_headers', 'vary_cookies', 'skip_paths', 'session_cookies'] as $key) {
      $lists[$key] = self::lines($values[$key] ?? '');
    }

    $dropped = array_diff($lists['skip_paths'], CachingChoices::normalisePaths($lists['skip_paths']));
    if (count(array_unique($lists['skip_paths'])) > CachingChoices::MAX_SKIP_PATHS) {
      $form_state->setErrorByName('caching][skip_paths', $this->t('At most @max paths.', ['@max' => CachingChoices::MAX_SKIP_PATHS]));
    }
    elseif ($dropped !== []) {
      $form_state->setErrorByName('caching][skip_paths', $this->t('These are not paths that can skip the cache: @paths. Start each with a slash; a bare / or /* would turn the cache off for the whole site.', ['@paths' => implode(', ', $dropped)]));
    }

    $refused = array_diff($lists['session_cookies'], CachingChoices::normaliseSessionCookies($lists['session_cookies']));
    if (count(array_unique($lists['session_cookies'])) > CachingChoices::MAX_SESSION_COOKIES) {
      $form_state->setErrorByName('caching][session_cookies', $this->t('At most @max.', ['@max' => CachingChoices::MAX_SESSION_COOKIES]));
    }
    elseif ($refused !== []) {
      $form_state->setErrorByName('caching][session_cookies', $this->t('These are not cookie names: @names. Letters, digits, dots, dashes and underscores only; a * would send every visitor past the cache.', ['@names' => implode(', ', $refused)]));
    }

    foreach ([
      'vary_headers' => [CachingChoices::MAX_HEADERS, CachingChoices::headerProblem(...)],
      'vary_cookies' => [CachingChoices::MAX_COOKIES, CachingChoices::cookieProblem(...)],
    ] as $key => [$max, $problem]) {
      $names = CachingChoices::names($lists[$key]);
      foreach ($names as $name) {
        $reason = $problem($name);
        if ($reason !== NULL) {
          $form_state->setErrorByName('caching][' . $key, $this->t('@name cannot be used: @reason.', [
            '@name' => $name,
            '@reason' => $reason,
          ]));
          continue 2;
        }
      }
      if (count($names) > $max) {
        $form_state->setErrorByName('caching][' . $key, $this->t('At most @max.', ['@max' => $max]));
      }
    }
    $vary = is_array($values['vary'] ?? NULL) ? $values['vary'] : [];
    if (!empty($vary[VaryCache::Cookie->value]) && $lists['vary_cookies'] === []) {
      $form_state->setErrorByName('caching][vary_cookies', $this->t('Name the cookies to vary by. Varying on every cookie a visitor carries would keep a copy per visitor.'));
    }
  }

  /**
   * Writes the saved caching to the pull zone.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function applyCaching(array &$form, FormStateInterface $form_state): void {
    try {
      $plan = $this->cachingSync->plan();
      if ($plan->isEmpty()) {
        $this->messenger()->addStatus($this->t('The pull zone already has these caching settings.'));
        return;
      }
      $this->cachingSync->apply($plan);
      $this->messenger()->addStatus($this->formatPlural(count($plan->describe()), 'Applied 1 change to the pull zone.', 'Applied @count changes to the pull zone.'));
    }
    catch (NotConfiguredException | \InvalidArgumentException | ApiException | ClientExceptionInterface $e) {
      $this->messenger()->addError($this->t('Caching was saved but not applied: @reason', ['@reason' => $e->getMessage()]));
    }
  }

  /**
   * The caching choices, written to the pull zone by a sync.
   *
   * @return array<string, mixed>
   *   The form elements.
   */
  private function caching(): array {
    $list = static fn(string $key): ConfigTarget => new ConfigTarget(
      'bunny_net.settings',
      'caching.' . $key,
      fromConfig: static fn(mixed $value): string => implode("\n", is_array($value) ? $value : []),
      toConfig: static fn(mixed $value): array => self::lines($value),
    );
    $states = ['visible' => [':input[name="caching[enabled]"]' => ['checked' => TRUE]]];
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Caching'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('What bunny.net caches and for how long, written to the pull zone as settings and Edge Rules with <code>drush bunny:caching-sync</code> or the button below. Pages Drupal marks private are never cached, and visitors with a Drupal session get pages from Drupal rather than the cache. The rules are named "@prefix…"; other rules on the zone are left alone.', ['@prefix' => CachingSync::PREFIX]),
    ];
    $element['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Manage the pull zone's caching from here"),
      '#config_target' => 'bunny_net.settings:caching.enabled',
    ];
    $element['dynamic'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let bunny.net decide what is cacheable'),
      '#description' => $this->t("bunny.net's Smart Cache: it caches what looks static, whatever Drupal says. Browser times are then left to Drupal."),
      '#config_target' => 'bunny_net.settings:caching.dynamic',
      '#states' => $states,
    ];
    $element['vary'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Keep a separate copy per'),
      '#options' => VaryCache::options(),
      '#config_target' => new ConfigTarget(
        'bunny_net.settings',
        'caching.vary',
        toConfig: static fn(mixed $value): array => array_values(array_filter(is_array($value) ? $value : [], is_string(...))),
      ),
      '#states' => $states,
    ];
    foreach (VaryCache::cases() as $vary) {
      $element['vary'][$vary->value]['#description'] = $vary->describe();
    }
    $element['vary_cookies'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cookies to vary by'),
      '#rows' => 3,
      '#description' => $this->t('One name per line, up to @max. Not a session cookie: that would be a copy per visitor.', ['@max' => CachingChoices::MAX_COOKIES]),
      '#config_target' => $list('vary_cookies'),
      '#states' => [
        'visible' => [
          ':input[name="caching[enabled]"]' => ['checked' => TRUE],
          ':input[name="caching[vary][cookie]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $element['vary_headers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Request headers to vary by'),
      '#rows' => 3,
      '#description' => $this->t('One name per line, up to @max, such as Accept-Language.', ['@max' => CachingChoices::MAX_HEADERS]),
      '#config_target' => $list('vary_headers'),
      '#states' => $states,
    ];
    $element['blocked_countries'] = [
      '#type' => 'select',
      '#title' => $this->t('Countries to block'),
      '#multiple' => TRUE,
      '#options' => array_map(strval(...), $this->countryManager->getList()),
      '#description' => $this->t('bunny.net answers visitors from these countries with a 403.'),
      '#config_target' => new ConfigTarget(
        'bunny_net.settings',
        'caching.blocked_countries',
        toConfig: static fn(mixed $value): array => array_values(array_map(strval(...), is_array($value) ? $value : [])),
      ),
      '#states' => $states,
    ];
    $element['skip_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Paths never cached'),
      '#rows' => 4,
      '#description' => $this->t('One per line, starting with a slash; * matches anything, as in /cart/*. Up to @max. Neither bunny.net nor browsers keep them.', ['@max' => CachingChoices::MAX_SKIP_PATHS]),
      '#config_target' => $list('skip_paths'),
      '#states' => $states,
    ];
    $element['session_cookies'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Other session cookies'),
      '#rows' => 3,
      '#description' => $this->t("Visitors carrying one of these get pages from Drupal, as visitors with a Drupal session (SESS…, SSESS…) already do. One name per line, or the start of one, up to @max. Only for a session Drupal doesn't set, such as a separate application's.", ['@max' => CachingChoices::MAX_SESSION_COOKIES]),
      '#config_target' => $list('session_cookies'),
      '#states' => $states,
    ];
    $element['browser_pages'] = [
      '#type' => 'select',
      '#title' => $this->t('Browsers keep pages for'),
      '#options' => $this->times(CachingChoices::PAGE_TIMES),
      '#description' => $this->t("Short, because bunny.net drops the Vary: Cookie header: a page a visitor saw signed out stays in their browser this long after they sign in."),
      '#config_target' => new ConfigTarget('bunny_net.settings', 'caching.browser_pages', toConfig: static fn(mixed $value): int => is_numeric($value) ? (int) $value : 0),
      '#states' => $states,
    ];
    $element['browser_files'] = [
      '#type' => 'select',
      '#title' => $this->t('Browsers keep static files for'),
      '#options' => $this->times(CachingChoices::FILE_TIMES),
      '#description' => $this->t('Stylesheets, scripts, images and fonts. Drupal gives changed aggregates new URLs.'),
      '#config_target' => new ConfigTarget('bunny_net.settings', 'caching.browser_files', toConfig: static fn(mixed $value): int => is_numeric($value) ? (int) $value : 0),
      '#states' => $states,
    ];
    $conflicts = $this->cachingSync->conflicts();
    if ($conflicts !== []) {
      $element['conflicts'] = [
        '#type' => 'item',
        '#markup' => $this->t('pull_zone_settings also sets @keys. Remove them there: caching sets them.', ['@keys' => implode(', ', $conflicts)]),
      ];
    }
    return $element;
  }

  /**
   * Browser times as options, after leaving it to Drupal.
   *
   * @param list<int> $times
   *   Seconds.
   *
   * @return array<int, string>
   *   Labels by seconds.
   */
  private function times(array $times): array {
    $options = [0 => (string) $this->t('What Drupal says')];
    foreach ($times as $seconds) {
      $options[$seconds] = (string) match (TRUE) {
        $seconds < 3600 => $this->formatPlural(intdiv($seconds, 60), '1 minute', '@count minutes'),
        $seconds < 86400 => $this->formatPlural(intdiv($seconds, 3600), '1 hour', '@count hours'),
        default => $this->formatPlural(intdiv($seconds, 86400), '1 day', '@count days'),
      };
    }
    return $options;
  }

  /**
   * A list from text, one item per line.
   *
   * @return list<string>
   *   The non-empty items, trimmed.
   */
  private static function lines(mixed $value): array {
    $items = is_array($value) ? $value : (preg_split('/\R/', is_string($value) ? $value : '') ?: []);
    return array_values(array_filter(array_map(static fn(mixed $item): string => trim(is_scalar($item) ? (string) $item : ''), $items), static fn(string $item): bool => $item !== ''));
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
