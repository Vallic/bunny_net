<?php

declare(strict_types=1);

namespace Drupal\bunny_net\PullZone;

use Valicm\BunnyToolkit\Caching\EdgeRulePlan;

/**
 * What a caching sync would change on the pull zone.
 */
final readonly class CachingPlan {

  /**
   * Constructs the plan.
   *
   * @param array<string, array{current: mixed, wanted: mixed}> $settings
   *   Pull zone settings that differ, by API name.
   * @param \Valicm\BunnyToolkit\Caching\EdgeRulePlan $rules
   *   The Edge Rules to delete and write.
   */
  public function __construct(
    public array $settings,
    public EdgeRulePlan $rules,
  ) {}

  /**
   * Whether the pull zone already matches.
   */
  public function isEmpty(): bool {
    return $this->settings === [] && $this->rules->isEmpty();
  }

  /**
   * The changes, one per line.
   *
   * @return list<string>
   *   Lines such as "update EnableWebpVary: false -> true".
   */
  public function describe(): array {
    $lines = [];
    foreach ($this->settings as $key => $change) {
      $lines[] = sprintf('update %s: %s -> %s', $key, self::inline($change['current']), self::inline($change['wanted']));
    }
    foreach ($this->rules->delete as $description) {
      $lines[] = sprintf('delete rule %s', $description);
    }
    foreach ($this->rules->save as $rule) {
      $lines[] = sprintf('%s rule %s', isset($rule['Guid']) ? 'update' : 'create', $rule['Description'] ?? '');
    }
    return $lines;
  }

  /**
   * A value on one line.
   */
  private static function inline(mixed $value): string {
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

}
