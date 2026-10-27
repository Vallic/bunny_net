<?php

declare(strict_types=1);

namespace Drupal\bunny_net\PullZone;

use Valicm\BunnyToolkit\Api\Core\Types;

/**
 * The settings bunny.net's update pull zone call takes, and their types.
 *
 * Read from the toolkit's PullZoneSettingsModel shape, which is generated
 * from bunny.net's API spec, so a new setting needs only a toolkit update.
 */
final class PullZoneFields {

  /**
   * Settings that hold credentials, which do not belong in exported config.
   */
  public const array SECRETS = ['AWSSigningKey', 'AWSSigningSecret', 'LogForwardingToken'];

  /**
   * The fields, once read.
   *
   * @var array<string, array{type: string, enum: class-string<\BackedEnum>|null}>|null
   */
  private static ?array $fields = NULL;

  /**
   * Every setting, in the API's order.
   *
   * @return array<string, array{type: string, enum: class-string<\BackedEnum>|null}>
   *   Fields by API name. The type is bool, int, float, string, enum, list
   *   or array; enum fields name their enum.
   */
  public static function all(): array {
    if (self::$fields === NULL) {
      $doc = (string) (new \ReflectionClass(Types::class))->getDocComment();
      if (preg_match('/@phpstan-type PullZoneSettingsModel array\{\n(.*?)\n \* \}/s', $doc, $shape) !== 1) {
        throw new \LogicException('The toolkit has no PullZoneSettingsModel type.');
      }
      preg_match_all('/^ \*\s+(\w+)\??: (.+?),?$/m', $shape[1], $lines, PREG_SET_ORDER);
      $fields = [];
      foreach ($lines as [, $key, $type]) {
        $fields[$key] = self::parse($type);
      }
      self::$fields = $fields;
    }
    return self::$fields;
  }

  /**
   * One field's type and enum.
   *
   * @return array{type: string, enum: class-string<\BackedEnum>|null}
   *   The field.
   */
  private static function parse(string $type): array {
    $type = (string) preg_replace('/\|null$/', '', $type);
    if (preg_match('/^value-of<\\\\?(.+)>$/', $type, $match) === 1 && is_subclass_of($match[1], \BackedEnum::class)) {
      return ['type' => 'enum', 'enum' => $match[1]];
    }
    if (str_starts_with($type, 'list<')) {
      return ['type' => 'list', 'enum' => NULL];
    }
    return ['type' => in_array($type, ['bool', 'int', 'float', 'string'], TRUE) ? $type : 'array', 'enum' => NULL];
  }

}
