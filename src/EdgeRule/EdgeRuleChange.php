<?php

declare(strict_types=1);

namespace Drupal\bunny_net\EdgeRule;

/**
 * One step of an Edge Rule sync.
 */
final readonly class EdgeRuleChange {

  /**
   * The file has no rule yet: add one.
   */
  public const string CREATE = 'create';

  /**
   * The file changed: update its rule.
   */
  public const string UPDATE = 'update';

  /**
   * An identical rule made in the bunny.net UI: mark it as the file's.
   */
  public const string ADOPT = 'adopt';

  /**
   * The rule matches its file.
   */
  public const string UNCHANGED = 'unchanged';

  /**
   * A managed rule whose file is gone: deleted only when pruning.
   */
  public const string DELETE = 'delete';

  /**
   * Constructs a change.
   *
   * @param string $operation
   *   One of the constants.
   * @param string $name
   *   The rule file's name.
   * @param string|null $guid
   *   The rule on bunny.net, unless it is created.
   * @param array<string, mixed>|null $body
   *   The request body to create, update or adopt with.
   */
  public function __construct(
    public string $operation,
    public string $name,
    public ?string $guid = NULL,
    public ?array $body = NULL,
  ) {}

  /**
   * Whether applying this changes anything on bunny.net.
   */
  public function isChange(): bool {
    return $this->operation !== self::UNCHANGED;
  }

}
