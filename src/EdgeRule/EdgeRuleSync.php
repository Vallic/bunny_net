<?php

declare(strict_types=1);

namespace Drupal\bunny_net\EdgeRule;

use Drupal\bunny_net\BunnyClientFactory;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\bunny_net\Paths;
use Drupal\bunny_net\PullZone\CachingSync;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Keeps a pull zone's Edge Rules in line with a directory of YAML files.
 *
 * Each file is one rule, named after the file. Rules made from a file carry
 * "[bunny_net:<name>]" at the end of their description, which is how a sync
 * finds them again. Rules without the marker, made in the bunny.net UI, are
 * left alone, unless a file describes one exactly: then the sync adopts it
 * instead of adding a copy.
 *
 * @phpstan-import-type Definition from \Drupal\bunny_net\EdgeRule\EdgeRuleDefinition
 */
final class EdgeRuleSync {

  /**
   * The rules directory, relative to the Drupal root, by default.
   */
  public const string DIRECTORY = Paths::DIRECTORY . '/rules';

  public function __construct(
    private readonly BunnyClientFactory $clientFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly string $appRoot,
  ) {}

  /**
   * The rules directory as an absolute path.
   *
   * The one edge_rules.directory names, or DIRECTORY when it is empty.
   */
  public function directory(): string {
    $directory = trim((string) $this->configFactory->get('bunny_net.settings')->get('edge_rules.directory'));
    return Paths::resolve($this->appRoot, $directory !== '' ? $directory : self::DIRECTORY);
  }

  /**
   * Whether the site has a rules directory to sync.
   */
  public function hasDirectory(): bool {
    return is_dir($this->directory());
  }

  /**
   * Reads every rule file in a directory.
   *
   * @return array<string, Definition>
   *   Rules keyed by file name, without the extension.
   *
   * @throws \InvalidArgumentException
   *   When the directory is missing or a file is not a valid rule.
   */
  public function read(string $directory): array {
    if (!is_dir($directory)) {
      throw new \InvalidArgumentException(sprintf('The Edge Rules directory %s does not exist.', $directory));
    }
    // scandir() rather than glob(), which can't read stream wrappers.
    $files = array_filter(scandir($directory) ?: [], static fn(string $file): bool => preg_match('/\.ya?ml$/', $file) === 1);
    sort($files);

    $rules = [];
    foreach ($files as $file) {
      $name = pathinfo($file, PATHINFO_FILENAME);
      $file = $directory . '/' . $file;
      if (!EdgeRuleDefinition::isValidName($name)) {
        throw new \InvalidArgumentException(sprintf('%s: use only letters, digits, ".", "_" and "-" in rule file names.', $file));
      }
      if (isset($rules[$name])) {
        throw new \InvalidArgumentException(sprintf('%s: another file is already named %s.', $file, $name));
      }
      $rules[$name] = EdgeRuleDefinition::fromYaml(Yaml::decode((string) file_get_contents($file)), $file);
      $order = $rules[$name]['order'];
      if ($order !== NULL && $this->cachingEnabled() && $order >= CachingSync::FIRST_ORDER && $order <= CachingSync::LAST_ORDER) {
        throw new \InvalidArgumentException(sprintf('%s: order %d is one of the caching rules\' places, %d to %d.', $file, $order, CachingSync::FIRST_ORDER, CachingSync::LAST_ORDER));
      }
    }
    return $rules;
  }

  /**
   * Works out what a sync would change.
   *
   * @return list<\Drupal\bunny_net\EdgeRule\EdgeRuleChange>
   *   The changes, unchanged rules included.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When the pull zone, directory or API key is missing.
   * @throws \InvalidArgumentException
   *   When a rule file is invalid.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function plan(): array {
    $files = $this->read($this->directory());

    $managed = [];
    $unmanaged = [];
    foreach ($this->remoteRules() as $rule) {
      $guid = is_string($rule['Guid'] ?? NULL) ? $rule['Guid'] : NULL;
      if ($guid === NULL || !empty($rule['ReadOnly'])) {
        continue;
      }
      $name = EdgeRuleDefinition::managedName(is_string($rule['Description'] ?? NULL) ? $rule['Description'] : '');
      if ($name !== NULL && !isset($managed[$name])) {
        $managed[$name] = ['guid' => $guid, 'definition' => EdgeRuleDefinition::fromApi($rule), 'rule' => $rule];
      }
      elseif ($name !== NULL) {
        // A second rule with the same marker: an earlier sync's duplicate.
        $managed[$name . '#' . $guid] = ['guid' => $guid, 'definition' => NULL, 'rule' => $rule];
      }
      elseif ($this->isCachingRule($rule)) {
        // CachingSync's, which only it changes.
        continue;
      }
      else {
        $unmanaged[$guid] = ['definition' => EdgeRuleDefinition::fromApi($rule), 'rule' => $rule];
      }
    }

    $changes = [];
    foreach ($files as $name => $definition) {
      if (isset($managed[$name])) {
        $guid = $managed[$name]['guid'];
        $remote = $managed[$name]['definition'];
        $changes[] = $remote !== NULL && EdgeRuleDefinition::matches($definition, $remote, $managed[$name]['rule'])
          ? new EdgeRuleChange(EdgeRuleChange::UNCHANGED, $name, $guid)
          : new EdgeRuleChange(EdgeRuleChange::UPDATE, $name, $guid, EdgeRuleDefinition::toApi($definition, $name, $guid));
        unset($managed[$name]);
        continue;
      }
      $guid = NULL;
      foreach ($unmanaged as $candidate => $remote) {
        if (EdgeRuleDefinition::matches($definition, $remote['definition'], $remote['rule'])) {
          $guid = (string) $candidate;
          break;
        }
      }
      if ($guid !== NULL) {
        unset($unmanaged[$guid]);
        $changes[] = new EdgeRuleChange(EdgeRuleChange::ADOPT, $name, $guid, EdgeRuleDefinition::toApi($definition, $name, $guid));
        continue;
      }
      $changes[] = new EdgeRuleChange(EdgeRuleChange::CREATE, $name, NULL, EdgeRuleDefinition::toApi($definition, $name));
    }
    foreach ($managed as $name => $rule) {
      $changes[] = new EdgeRuleChange(EdgeRuleChange::DELETE, explode('#', (string) $name)[0], $rule['guid']);
    }
    return $changes;
  }

  /**
   * Applies one change from plan().
   *
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function apply(EdgeRuleChange $change): void {
    $pullZone = $this->clientFactory->get()->core()->pullZone();
    match ($change->operation) {
      EdgeRuleChange::CREATE, EdgeRuleChange::UPDATE, EdgeRuleChange::ADOPT => $pullZone->addOrUpdateEdgeRule($this->pullZoneId(), $change->body ?? []),
      EdgeRuleChange::DELETE => $pullZone->deleteEdgeRule($this->pullZoneId(), (string) $change->guid),
      default => NULL,
    };
  }

  /**
   * Writes the pull zone's rules into a directory as YAML files.
   *
   * @param string $directory
   *   Where to write; created if missing.
   * @param bool $overwrite
   *   Whether to replace files that exist.
   *
   * @return array<string, string>
   *   Status of each file by path: "written" or "exists".
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When the pull zone or API key is missing.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function export(string $directory, bool $overwrite = FALSE): array {
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE)) {
      throw new \RuntimeException(sprintf('Cannot create %s.', $directory));
    }
    $statuses = [];
    $names = [];
    foreach ($this->remoteRules() as $rule) {
      if (!empty($rule['ReadOnly']) || $this->isCachingRule($rule)) {
        continue;
      }
      $description = is_string($rule['Description'] ?? NULL) ? $rule['Description'] : '';
      $name = EdgeRuleDefinition::managedName($description) ?? self::slug($description, is_string($rule['Guid'] ?? NULL) ? $rule['Guid'] : '');
      $unique = $name;
      for ($i = 2; isset($names[$unique]); $i++) {
        $unique = $name . '-' . $i;
      }
      $names[$unique] = TRUE;

      $file = $directory . '/' . $unique . '.yml';
      if (file_exists($file) && !$overwrite) {
        $statuses[$file] = 'exists';
        continue;
      }
      file_put_contents($file, Yaml::encode(EdgeRuleDefinition::toYaml(EdgeRuleDefinition::fromApi($rule))));
      $statuses[$file] = 'written';
    }
    return $statuses;
  }

  /**
   * Whether the caching settings write their own rules to the zone.
   */
  private function cachingEnabled(): bool {
    return (bool) $this->configFactory->get('bunny_net.settings')->get('caching.enabled');
  }

  /**
   * Whether a rule is one of CachingSync's.
   *
   * @param array<mixed> $rule
   *   An EdgeRuleV2Model.
   */
  private function isCachingRule(array $rule): bool {
    return is_string($rule['Description'] ?? NULL) && str_starts_with($rule['Description'], CachingSync::PREFIX);
  }

  /**
   * The pull zone's Edge Rules.
   *
   * @return list<array<mixed>>
   *   EdgeRuleV2Model arrays.
   */
  private function remoteRules(): array {
    $zone = $this->clientFactory->get()->core()->pullZone()->getPullZone($this->pullZoneId());
    return array_values(array_filter($zone['EdgeRules'] ?? [], is_array(...)));
  }

  /**
   * The configured pull zone.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When none is set.
   */
  private function pullZoneId(): int {
    $id = (int) $this->configFactory->get('bunny_net.settings')->get('pull_zone_id');
    if ($id <= 0) {
      throw new NotConfiguredException('No pull zone: set pull_zone_id in bunny_net.settings.');
    }
    return $id;
  }

  /**
   * A file name for a rule made in the UI.
   */
  private static function slug(string $description, string $guid): string {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($description)), '-');
    return rtrim(substr($slug, 0, 60), '-') ?: 'rule-' . substr($guid, 0, 8);
  }

}
