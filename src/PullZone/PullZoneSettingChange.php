<?php

declare(strict_types=1);

namespace Drupal\bunny_net\PullZone;

/**
 * One setting in a pull zone settings sync.
 */
final readonly class PullZoneSettingChange {

  /**
   * The pull zone has another value: send the configured one.
   */
  public const string UPDATE = 'update';

  /**
   * The pull zone already has the configured value.
   */
  public const string UNCHANGED = 'unchanged';

  /**
   * Not returned by bunny.net, so the setting is sent on every sync.
   */
  public const string UNREADABLE = 'send';

  /**
   * Constructs a change.
   *
   * @param string $operation
   *   One of the constants.
   * @param string $key
   *   The setting's API name.
   * @param mixed $current
   *   The pull zone's value, as written in config; NULL when unreadable.
   * @param mixed $configured
   *   The configured value, as written in config.
   * @param mixed $value
   *   The configured value as the API takes it.
   */
  public function __construct(
    public string $operation,
    public string $key,
    public mixed $current,
    public mixed $configured,
    public mixed $value,
  ) {}

  /**
   * Whether a sync sends this setting.
   */
  public function isChange(): bool {
    return $this->operation !== self::UNCHANGED;
  }

}
