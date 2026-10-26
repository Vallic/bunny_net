<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Drush\Commands;

use Drupal\bunny_net\CachePurger;
use Drupal\bunny_net\EdgeRule\EdgeRuleChange;
use Drupal\bunny_net\EdgeRule\EdgeRuleSync;
use Drupal\bunny_net\EdgeScript\ScriptDeployer;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Valicm\BunnyToolkit\Purge\PurgeResult;

/**
 * Drush commands for purging, Edge Script deploys and Edge Rule syncs.
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
