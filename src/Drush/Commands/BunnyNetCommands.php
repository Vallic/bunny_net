<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\bunny_net\CachePurger;
use Drupal\bunny_net\EdgeRule\EdgeRuleChange;
use Drupal\bunny_net\EdgeRule\EdgeRuleSync;
use Drupal\bunny_net\EdgeScript\ScriptDeployer;
use Drupal\bunny_net\PullZone\CachingSync;
use Drupal\bunny_net\PullZone\PullZoneSettingChange;
use Drupal\bunny_net\PullZone\PullZoneSettings;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Commands\core\DeployCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Valicm\BunnyToolkit\Purge\PurgeResult;

/**
 * Drush commands for purging, deploys and syncs of the pull zone's setup.
 */
final class BunnyNetCommands extends DrushCommands {

  /**
   * Seconds a purge from the command line may wait for the rate limits.
   */
  private const float MAX_WAIT = 10.0;

  public function __construct(
    private readonly CachePurger $purger,
    private readonly ScriptDeployer $scriptDeployer,
    private readonly EdgeRuleSync $edgeRuleSync,
    private readonly PullZoneSettings $pullZoneSettings,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CachingSync $cachingSync,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bunny_net.purger'),
      $container->get('bunny_net.script_deployer'),
      $container->get('bunny_net.edge_rule_sync'),
      $container->get('bunny_net.pull_zone_settings'),
      $container->get('config.factory'),
      $container->get('bunny_net.caching_sync'),
    );
  }

  /**
   * Purges the pages carrying the given cache tags.
   */
  #[CLI\Command(name: 'bunny:purge-tags', aliases: ['bunny-pt'])]
  #[CLI\Argument(name: 'tags', description: 'Comma-separated Drupal cache tags.')]
  #[CLI\Usage(name: 'drush bunny:purge-tags node:12,node_list', description: 'Purge pages showing node 12 or a node listing.')]
  public function purgeTags(string $tags): void {
    $this->report($this->purger->purgeTags(self::split($tags), self::MAX_WAIT), 'tag(s)');
  }

  /**
   * Purges URLs; a * in a URL purges everything under it.
   */
  #[CLI\Command(name: 'bunny:purge-url', aliases: ['bunny-pu'])]
  #[CLI\Argument(name: 'urls', description: 'Comma-separated absolute URLs.')]
  #[CLI\Usage(name: 'drush bunny:purge-url "https://example.com/lots/*"', description: 'Purge everything under /lots/.')]
  public function purgeUrls(string $urls): void {
    $this->report($this->purger->purgeUrls(self::split($urls), self::MAX_WAIT), 'URL(s)');
  }

  /**
   * Empties the whole pull zone.
   */
  #[CLI\Command(name: 'bunny:purge-all', aliases: ['bunny-pa'])]
  public function purgeAll(): void {
    $this->purger->ensureEnabled();
    if (!$this->io()->confirm(dt('Purge everything in pull zone @zone?', ['@zone' => $this->purger->pullZoneId()]))) {
      return;
    }
    $this->report($this->purger->purgeAll(self::MAX_WAIT), 'pull zone(s)');
  }

  /**
   * Deploys Edge Scripts from their files.
   *
   * Scripts whose code already matches the file are skipped.
   *
   * @param string $names
   *   Comma-separated script names.
   * @param array{force: bool, no-publish: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:script-deploy', aliases: ['bunny-sd'])]
  #[CLI\Argument(name: 'names', description: 'Comma-separated script names from bunny_net.settings; all when left out.')]
  #[CLI\Option(name: 'force', description: 'Deploy unchanged scripts too, e.g. after changing their variables.')]
  #[CLI\Option(name: 'no-publish', description: 'Upload the code without publishing a release.')]
  #[CLI\Usage(name: 'drush bunny:script-deploy', description: 'Deploy every configured script that changed.')]
  public function scriptDeploy(string $names = '', array $options = ['force' => FALSE, 'no-publish' => FALSE]): void {
    $names = $names === '' ? $this->scriptDeployer->names() : self::split($names);
    if ($names === []) {
      $this->io()->warning(dt('No Edge Scripts in bunny_net.settings.'));
      return;
    }
    foreach ($names as $name) {
      $status = $this->scriptDeployer->deploy($name, (bool) $options['force'], !$options['no-publish']);
      $this->io()->success(dt('@name: @status', ['@name' => $name, '@status' => $status]));
    }
  }

  /**
   * Syncs the pull zone's Edge Rules with the rules directory.
   *
   * Shows the changes and asks before making them. Rules made in the
   * bunny.net UI are left alone; managed rules whose file is gone are only
   * deleted with --prune.
   *
   * @param array{dry-run: bool, prune: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:rules-sync', aliases: ['bunny-rs'])]
  #[CLI\Option(name: 'dry-run', description: 'Show the changes without making them.')]
  #[CLI\Option(name: 'prune', description: 'Delete managed rules whose file is gone.')]
  public function rulesSync(array $options = ['dry-run' => FALSE, 'prune' => FALSE]): void {
    $changes = $this->edgeRuleSync->plan();
    $pending = array_filter($changes, static fn(EdgeRuleChange $change): bool => $change->isChange() && ($change->operation !== EdgeRuleChange::DELETE || $options['prune']));

    foreach ($changes as $change) {
      $note = $change->operation === EdgeRuleChange::DELETE && !$options['prune'] ? ' (kept; --prune deletes it)' : '';
      $this->io()->writeln(sprintf('  %-9s %s%s', $change->operation, $change->name, $note));
    }
    if ($pending === []) {
      $this->io()->success(dt('Edge Rules are in sync.'));
      return;
    }
    if ($options['dry-run'] || !$this->io()->confirm(dt('Apply @count change(s)?', ['@count' => count($pending)]))) {
      return;
    }
    foreach ($pending as $change) {
      $this->edgeRuleSync->apply($change);
    }
    $this->io()->success(dt('Applied @count change(s).', ['@count' => count($pending)]));
  }

  /**
   * Writes the pull zone's Edge Rules into a directory as YAML files.
   *
   * A starting point for the rules directory: once the files are synced,
   * the rules they describe are managed from them.
   *
   * @param string $directory
   *   Where to write the files.
   * @param array{overwrite: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:rules-export', aliases: ['bunny-re'])]
  #[CLI\Argument(name: 'directory', description: 'Where to write the files; the configured rules directory when left out.')]
  #[CLI\Option(name: 'overwrite', description: 'Replace files that exist.')]
  public function rulesExport(string $directory = '', array $options = ['overwrite' => FALSE]): void {
    $statuses = $this->edgeRuleSync->export($directory === '' ? $this->edgeRuleSync->directory() : $directory, (bool) $options['overwrite']);
    foreach ($statuses as $file => $status) {
      $this->io()->writeln(sprintf('  %-7s %s', $status, $file));
    }
    $written = array_filter($statuses, static fn(string $status): bool => $status === 'written');
    $this->io()->success(dt('Exported @count rule(s).', ['@count' => count($written)]));
  }

  /**
   * Syncs the pull zone's settings with pull_zone_settings in config.
   *
   * Shows the settings that differ and asks before sending them. Settings
   * left out of config are not touched.
   *
   * @param array{dry-run: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:zone-sync', aliases: ['bunny-zs'])]
  #[CLI\Option(name: 'dry-run', description: 'Show the changes without making them.')]
  public function zoneSync(array $options = ['dry-run' => FALSE]): void {
    $changes = $this->pullZoneSettings->plan();
    if ($changes === []) {
      $this->io()->warning(dt('No pull_zone_settings or error page in bunny_net.settings.'));
      return;
    }
    foreach ($changes as $change) {
      $this->io()->writeln(match ($change->operation) {
        PullZoneSettingChange::UPDATE => sprintf('  %-9s %s: %s -> %s', $change->operation, $change->key, self::inline($change->current), self::inline($change->configured)),
        PullZoneSettingChange::UNREADABLE => sprintf('  %-9s %s: %s (bunny.net does not return it)', $change->operation, $change->key, self::inline($change->configured)),
        default => sprintf('  %-9s %s: %s', $change->operation, $change->key, self::inline($change->configured)),
      });
    }
    $pending = array_filter($changes, static fn(PullZoneSettingChange $change): bool => $change->isChange());
    if ($pending === []) {
      $this->io()->success(dt('Pull zone settings are in sync.'));
      return;
    }
    if ($options['dry-run'] || !$this->io()->confirm(dt('Send @count setting(s) to pull zone @zone?', [
      '@count' => count($pending),
      '@zone' => $this->configFactory->get('bunny_net.settings')->get('pull_zone_id'),
    ]))) {
      return;
    }
    $ignored = $this->pullZoneSettings->apply($changes);
    $this->io()->success(dt('Sent @count setting(s).', ['@count' => count($pending)]));
    if ($ignored !== []) {
      $this->io()->warning(dt('bunny.net kept other values for: @keys', ['@keys' => implode(', ', $ignored)]));
    }
  }

  /**
   * Syncs the pull zone's settings, caching and Edge Rules in one go.
   *
   * What bunny:zone-sync, bunny:caching-sync and bunny:rules-sync each do,
   * in that order, for a deploy script: each part runs only where it is
   * configured, every change is shown, and one answer applies them all.
   *
   * @param array{dry-run: bool, prune: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:sync', aliases: ['bunny-sync'])]
  #[CLI\Option(name: 'dry-run', description: 'Show the changes without making them.')]
  #[CLI\Option(name: 'prune', description: 'Delete managed Edge Rules whose file is gone.')]
  #[CLI\Usage(name: 'drush bunny:sync -y', description: 'Bring the pull zone in line with config after a deploy.')]
  public function sync(array $options = ['dry-run' => FALSE, 'prune' => FALSE]): void {
    $this->syncZone((bool) $options['dry-run'], (bool) $options['prune'], TRUE);
  }

  /**
   * After drush deploy, where sync_on_deploy is on: scripts, then the zone.
   *
   * What bunny:script-deploy and bunny:sync do, without asking, once config
   * is imported. Scripts go first, so a pull zone setting naming a script
   * (MiddlewareScriptId) never points at code that isn't there yet. A
   * failure is reported and doesn't fail the deploy: the site is already
   * live, and the next deploy, or the commands by hand, try again.
   *
   * @param mixed $result
   *   The deploy command's result.
   * @param \Consolidation\AnnotatedCommand\CommandData $commandData
   *   The deploy command's data.
   */
  #[CLI\Hook(type: HookManager::POST_COMMAND_HOOK, target: DeployCommands::DEPLOY)]
  public function postDeploy(mixed $result, CommandData $commandData): void {
    if (!$this->configFactory->get('bunny_net.settings')->get('sync_on_deploy')) {
      return;
    }
    $this->io()->section(dt('Bunny CDN'));
    foreach ($this->scriptDeployer->names() as $name) {
      try {
        $this->io()->writeln(sprintf('  script   %s: %s', $name, $this->scriptDeployer->deploy($name)));
      }
      catch (\Throwable $e) {
        $this->logger()?->warning(dt('Edge Script @name was not deployed: @reason. Run drush bunny:script-deploy.', [
          '@name' => $name,
          '@reason' => $e->getMessage(),
        ]));
      }
    }
    try {
      $this->syncZone(FALSE, FALSE, FALSE);
    }
    catch (\Throwable $e) {
      $this->logger()?->warning(dt('The pull zone was not brought in line with config: @reason. Run drush bunny:sync.', ['@reason' => $e->getMessage()]));
    }
  }

  /**
   * Plans the zone's settings, caching and rules, shows them, and applies them.
   *
   * @param bool $dryRun
   *   Only show the changes.
   * @param bool $prune
   *   Delete managed Edge Rules whose file is gone.
   * @param bool $confirm
   *   Ask before applying.
   */
  private function syncZone(bool $dryRun, bool $prune, bool $confirm): void {
    $hasRules = $this->edgeRuleSync->hasDirectory();
    if (!$this->pullZoneSettings->isConfigured() && !$this->cachingSync->isEnabled() && !$hasRules) {
      $this->io()->warning(dt('Nothing to sync: no pull_zone_settings or error page, caching is not managed here, and there is no Edge Rules directory at @directory.', ['@directory' => $this->edgeRuleSync->directory()]));
      return;
    }
    $settings = array_values(array_filter($this->pullZoneSettings->plan(), static fn(PullZoneSettingChange $change): bool => $change->isChange()));
    $caching = $this->cachingSync->isEnabled() ? $this->cachingSync->plan() : NULL;
    $rules = [];
    if ($hasRules) {
      $rules = array_values(array_filter($this->edgeRuleSync->plan(), static fn(EdgeRuleChange $change): bool => $change->isChange() && ($change->operation !== EdgeRuleChange::DELETE || $prune)));
    }

    $lines = [
      ...array_map(static fn(PullZoneSettingChange $change): string => sprintf('setting  %s: %s', $change->key, self::inline($change->configured)), $settings),
      ...array_map(static fn(string $line): string => 'caching  ' . $line, $caching?->describe() ?? []),
      ...array_map(static fn(EdgeRuleChange $change): string => sprintf('rule     %s %s', $change->operation, $change->name), $rules),
    ];
    $zone = $this->configFactory->get('bunny_net.settings')->get('pull_zone_id');
    if ($lines === []) {
      $this->io()->success(dt('Pull zone @zone is in sync.', ['@zone' => $zone]));
      return;
    }
    foreach ($lines as $line) {
      $this->io()->writeln('  ' . $line);
    }
    $counts = ['@count' => count($lines), '@zone' => $zone];
    if ($dryRun || ($confirm && !$this->io()->confirm(dt('Apply @count change(s) to pull zone @zone?', $counts)))) {
      return;
    }

    $ignored = $settings === [] ? [] : $this->pullZoneSettings->apply($settings);
    if ($caching !== NULL && !$caching->isEmpty()) {
      $this->cachingSync->apply($caching);
    }
    foreach ($rules as $change) {
      $this->edgeRuleSync->apply($change);
    }
    $this->io()->success(dt('Applied @count change(s) to pull zone @zone.', $counts));
    if ($ignored !== []) {
      $this->io()->warning(dt('Bunny CDN kept other values for: @keys', ['@keys' => implode(', ', $ignored)]));
    }
  }

  /**
   * Syncs the pull zone's caching with caching in config.
   *
   * Shows the settings and Edge Rules that differ and asks before sending
   * them. Rules not written by this command are left alone.
   *
   * @param array{dry-run: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:caching-sync', aliases: ['bunny-cs'])]
  #[CLI\Option(name: 'dry-run', description: 'Show the changes without making them.')]
  public function cachingSync(array $options = ['dry-run' => FALSE]): void {
    $plan = $this->cachingSync->plan();
    foreach ($plan->describe() as $line) {
      $this->io()->writeln('  ' . $line);
    }
    if ($plan->isEmpty()) {
      $this->io()->success(dt('Caching is in sync.'));
      return;
    }
    if ($options['dry-run'] || !$this->io()->confirm(dt('Apply @count change(s) to pull zone @zone?', [
      '@count' => count($plan->describe()),
      '@zone' => $this->configFactory->get('bunny_net.settings')->get('pull_zone_id'),
    ]))) {
      return;
    }
    $this->cachingSync->apply($plan);
    $this->io()->success(dt('Applied @count change(s).', ['@count' => count($plan->describe())]));
  }

  /**
   * Prints the settings recommended for Drupal, or saves them to config.
   *
   * Each comes with the reason. A new install has them already; for a site
   * installed before, --save adds them to pull_zone_settings, and settings
   * already there keep their value. bunny:zone-sync sends them.
   *
   * @param array{save: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:zone-recommend', aliases: ['bunny-zr'])]
  #[CLI\Option(name: 'save', description: 'Add them to pull_zone_settings in the active config, keeping values already set.')]
  #[CLI\Usage(name: 'drush bunny:zone-recommend --save && drush bunny:zone-sync --dry-run', description: 'Adopt the recommendations and see what they would change.')]
  public function zoneRecommend(array $options = ['save' => FALSE]): void {
    $configured = $this->pullZoneSettings->configured();
    $this->io()->writeln('pull_zone_settings:');
    foreach (PullZoneSettings::RECOMMENDED as $key => [$value, $reason]) {
      $this->io()->writeln('  # ' . $reason);
      $kept = array_key_exists($key, $configured) && $configured[$key] !== $value ? ' # configured: ' . self::inline($configured[$key]) : '';
      $this->io()->writeln(sprintf('  %s: %s%s', $key, self::inline($value), $kept));
    }
    $this->io()->writeln('  # VerifyOriginSSL: true, once the origin has a certificate for the public hostname: with AddHostHeader, bunny.net asks the origin for the visitor\'s domain.');
    if (!$options['save']) {
      return;
    }
    $added = array_diff_key(array_map(static fn(array $recommended): bool => $recommended[0], PullZoneSettings::RECOMMENDED), $configured);
    $this->configFactory->getEditable('bunny_net.settings')->set('pull_zone_settings', [...$configured, ...$added])->save();
    $this->io()->success(dt('Added @count setting(s); export the config to keep them, and run drush bunny:zone-sync to send them.', ['@count' => count($added)]));
  }

  /**
   * Prints the pull zone's settings as pull_zone_settings YAML.
   *
   * A starting point for config: copy the settings to manage, or save them
   * to the active config with --save and export it.
   *
   * @param string $keys
   *   Comma-separated setting names.
   * @param array{save: bool} $options
   *   The command options.
   */
  #[CLI\Command(name: 'bunny:zone-export', aliases: ['bunny-ze'])]
  #[CLI\Argument(name: 'keys', description: 'Comma-separated settings, by bunny.net API name; the configured ones when left out, or all with "all".')]
  #[CLI\Option(name: 'save', description: 'Save the values to pull_zone_settings in the active config.')]
  #[CLI\Usage(name: 'drush bunny:zone-export EnableCookieVary,CookieVaryParameters --save', description: 'Start managing cookie vary from config.')]
  public function zoneExport(string $keys = '', array $options = ['save' => FALSE]): void {
    $names = match ($keys) {
      '' => array_map(strval(...), array_keys($this->pullZoneSettings->configured())),
      'all' => [],
      default => self::split($keys),
    };
    if ($names === [] && $keys !== 'all') {
      $this->io()->warning(dt('No pull_zone_settings in bunny_net.settings: name the settings to export, or "all".'));
      return;
    }
    $values = $this->pullZoneSettings->export($names);
    $this->io()->writeln(Yaml::encode(['pull_zone_settings' => $values]));
    if ($options['save']) {
      $config = $this->configFactory->getEditable('bunny_net.settings');
      $config->set('pull_zone_settings', [...$this->pullZoneSettings->configured(), ...$values])->save();
      $this->io()->success(dt('Saved @count setting(s); export the config to keep them.', ['@count' => count($values)]));
    }
  }

  /**
   * A value on one line; a long text, such as the error page, shortened.
   */
  private static function inline(mixed $value): string {
    if (is_string($value) && mb_strlen($value) > 60) {
      return sprintf('"%s…" (%d characters)', str_replace(["\r", "\n"], ' ', mb_substr($value, 0, 40)), mb_strlen($value));
    }
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Reports a purge, and what was queued for cron.
   */
  private function report(PurgeResult $result, string $what): void {
    $this->io()->success(dt('Purged @count @what.', ['@count' => count($result->purged), '@what' => $what]));
    if (!$result->isComplete()) {
      $this->io()->warning(dt('Queued @count for cron: @reason', [
        '@count' => count($result->deferred),
        '@reason' => $result->reason ?? '',
      ]));
    }
  }

  /**
   * Splits a comma-separated argument.
   *
   * @return list<string>
   *   The non-empty values.
   */
  private static function split(string $value): array {
    return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn(string $item): bool => $item !== ''));
  }

}
