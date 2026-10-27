<?php

declare(strict_types=1);

namespace Drupal\bunny_net\EdgeRule;

use Valicm\BunnyToolkit\Api\Core\Enum\EdgeRuleActionType;
use Valicm\BunnyToolkit\Api\Core\Enum\PatternMatchingTypes;
use Valicm\BunnyToolkit\Api\Core\Enum\TriggerMatchingTypes;
use Valicm\BunnyToolkit\Api\Core\Enum\TriggerTypes;

/**
 * Converts Edge Rules between YAML files and the bunny.net API.
 *
 * A rule file names values instead of numbering them:
 *
 * @code
 * description: Block WordPress probes
 * action: BlockRequest
 * match: MatchAny
 * triggers:
 *   - type: Url
 *     match: MatchAny
 *     patterns: ['*wp-login.php', '*xmlrpc.php']
 * @endcode
 *
 * `order` places the rule among the zone's rules, as bunny.net's OrderIndex;
 * left out, bunny.net places it and a sync leaves its place alone.
 *
 * Names are the toolkit's enum cases (EdgeRuleActionType, TriggerTypes and
 * so on). A number is accepted too, for a value bunny.net added after the
 * toolkit was generated.
 *
 * Both sides convert to one canonical array, so a file and the rule
 * bunny.net returns compare equal when they would behave the same.
 *
 * @phpstan-type Trigger array{type: string|int, match: string|int, patterns: list<string>, parameter: string}
 * @phpstan-type ExtraAction array{action: string|int, parameters: list<string>}
 * @phpstan-type Definition array{
 *   description: string,
 *   order: int|null,
 *   enabled: bool,
 *   action: string|int,
 *   parameters: list<string>,
 *   match: string|int,
 *   triggers: list<Trigger>,
 *   extra_actions: list<ExtraAction>,
 * }
 */
final class EdgeRuleDefinition {

  /**
   * Edge Rules take up to three action parameters.
   */
  private const int MAX_PARAMETERS = 3;

  /**
   * Marks a rule in its description as managed from a file.
   *
   * The file's name goes in the marker, so the rule is found again in every
   * environment without keeping its GUID anywhere.
   */
  private const string MARKER = '/\s*\[bunny_net:([A-Za-z0-9_.-]+)\]$/';

  /**
   * Reads a rule file's contents.
   *
   * @param mixed $data
   *   The decoded YAML.
   * @param string $source
   *   The file, for error messages.
   *
   * @return Definition
   *   The canonical rule.
   *
   * @throws \InvalidArgumentException
   *   When the file is not a valid rule.
   */
  public static function fromYaml(mixed $data, string $source): array {
    if (!is_array($data)) {
      throw self::invalid($source, 'the file must hold a mapping');
    }
    $keys = ['description', 'order', 'enabled', 'action', 'parameters', 'match', 'triggers', 'extra_actions'];
    $unknown = array_diff(array_keys($data), $keys);
    if ($unknown !== []) {
      throw self::invalid($source, sprintf('unknown key(s) %s', implode(', ', $unknown)));
    }
    if (!isset($data['action'])) {
      throw self::invalid($source, 'action is required');
    }
    if (!is_array($data['triggers'] ?? NULL) || $data['triggers'] === []) {
      throw self::invalid($source, 'at least one trigger is required');
    }

    $triggers = [];
    foreach (array_values($data['triggers']) as $index => $trigger) {
      $where = sprintf('triggers[%d]', $index);
      if (!is_array($trigger) || !isset($trigger['type'])) {
        throw self::invalid($source, $where . ' needs a type');
      }
      $unknown = array_diff(array_keys($trigger), ['type', 'match', 'patterns', 'parameter']);
      if ($unknown !== []) {
        throw self::invalid($source, sprintf('%s has unknown key(s) %s', $where, implode(', ', $unknown)));
      }
      $triggers[] = [
        'type' => self::enumName(TriggerTypes::class, $trigger['type'], $source, $where . '.type'),
        'match' => self::enumName(PatternMatchingTypes::class, $trigger['match'] ?? 'MatchAny', $source, $where . '.match'),
        'patterns' => self::strings($trigger['patterns'] ?? [], $source, $where . '.patterns'),
        'parameter' => self::string($trigger['parameter'] ?? '', $source, $where . '.parameter'),
      ];
    }

    $extraActions = [];
    foreach (array_values(is_array($data['extra_actions'] ?? NULL) ? $data['extra_actions'] : []) as $index => $action) {
      $where = sprintf('extra_actions[%d]', $index);
      if (!is_array($action) || !isset($action['action'])) {
        throw self::invalid($source, $where . ' needs an action');
      }
      $extraActions[] = [
        'action' => self::enumName(EdgeRuleActionType::class, $action['action'], $source, $where . '.action'),
        'parameters' => self::parameters($action['parameters'] ?? [], $source, $where . '.parameters'),
      ];
    }

    if (isset($data['enabled']) && !is_bool($data['enabled'])) {
      throw self::invalid($source, 'enabled must be true or false');
    }
    if (isset($data['order']) && (!is_int($data['order']) || $data['order'] < 0)) {
      throw self::invalid($source, 'order must be a whole number, 0 or more');
    }

    return [
      'description' => self::string($data['description'] ?? '', $source, 'description'),
      'order' => $data['order'] ?? NULL,
      'enabled' => $data['enabled'] ?? TRUE,
      'action' => self::enumName(EdgeRuleActionType::class, $data['action'], $source, 'action'),
      'parameters' => self::parameters($data['parameters'] ?? [], $source, 'parameters'),
      'match' => self::enumName(TriggerMatchingTypes::class, $data['match'] ?? 'MatchAny', $source, 'match'),
      'triggers' => $triggers,
      'extra_actions' => $extraActions,
    ];
  }

  /**
   * Reads a rule as the bunny.net API returns it.
   *
   * @param array<mixed> $rule
   *   An EdgeRuleV2Model.
   *
   * @return Definition
   *   The canonical rule, without the description's marker. Its order is
   *   left out: a file without one doesn't care, see orderOf().
   */
  public static function fromApi(array $rule): array {
    $triggers = [];
    foreach (is_array($rule['Triggers'] ?? NULL) ? $rule['Triggers'] : [] as $trigger) {
      if (!is_array($trigger)) {
        continue;
      }
      $triggers[] = [
        'type' => self::nameOf(TriggerTypes::class, $trigger['Type'] ?? 0),
        'match' => self::nameOf(PatternMatchingTypes::class, $trigger['PatternMatchingType'] ?? 0),
        'patterns' => array_values(array_map(strval(...), array_filter(is_array($trigger['PatternMatches'] ?? NULL) ? $trigger['PatternMatches'] : [], is_scalar(...)))),
        'parameter' => is_string($trigger['Parameter1'] ?? NULL) ? $trigger['Parameter1'] : '',
      ];
    }
    $extraActions = [];
    foreach (is_array($rule['ExtraActions'] ?? NULL) ? $rule['ExtraActions'] : [] as $action) {
      if (is_array($action)) {
        $extraActions[] = [
          'action' => self::nameOf(EdgeRuleActionType::class, $action['ActionType'] ?? 0),
          'parameters' => self::apiParameters($action),
        ];
      }
    }

    return [
      'description' => self::withoutMarker(is_string($rule['Description'] ?? NULL) ? $rule['Description'] : ''),
      'order' => NULL,
      'enabled' => (bool) ($rule['Enabled'] ?? FALSE),
      'action' => self::nameOf(EdgeRuleActionType::class, $rule['ActionType'] ?? 0),
      'parameters' => self::apiParameters($rule),
      'match' => self::nameOf(TriggerMatchingTypes::class, $rule['TriggerMatchingType'] ?? 0),
      'triggers' => $triggers,
      'extra_actions' => $extraActions,
    ];
  }

  /**
   * The API request body for a rule.
   *
   * @param Definition $definition
   *   The canonical rule.
   * @param string $name
   *   The rule file's name, kept in the description's marker.
   * @param string|null $guid
   *   The rule to update, or NULL to add one.
   *
   * @return array<string, mixed>
   *   An EdgeRuleV2Model.
   */
  public static function toApi(array $definition, string $name, ?string $guid = NULL): array {
    $body = [
      'ActionType' => self::valueOf(EdgeRuleActionType::class, $definition['action']),
      'ActionParameter1' => $definition['parameters'][0] ?? '',
      'ActionParameter2' => $definition['parameters'][1] ?? '',
      'ActionParameter3' => $definition['parameters'][2] ?? '',
      'TriggerMatchingType' => self::valueOf(TriggerMatchingTypes::class, $definition['match']),
      'Triggers' => array_map(static fn(array $trigger): array => [
        'Type' => self::valueOf(TriggerTypes::class, $trigger['type']),
        'PatternMatchingType' => self::valueOf(PatternMatchingTypes::class, $trigger['match']),
        'PatternMatches' => $trigger['patterns'],
        'Parameter1' => $trigger['parameter'],
      ], $definition['triggers']),
      'ExtraActions' => array_map(static fn(array $action): array => [
        'ActionType' => self::valueOf(EdgeRuleActionType::class, $action['action']),
        'ActionParameter1' => $action['parameters'][0] ?? '',
        'ActionParameter2' => $action['parameters'][1] ?? '',
        'ActionParameter3' => $action['parameters'][2] ?? '',
      ], $definition['extra_actions']),
      'Description' => trim($definition['description'] . ' [bunny_net:' . $name . ']'),
      'Enabled' => $definition['enabled'],
    ];
    if ($definition['order'] !== NULL) {
      $body['OrderIndex'] = $definition['order'];
    }
    if ($guid !== NULL) {
      $body = ['Guid' => $guid] + $body;
    }
    return $body;
  }

  /**
   * The rule as a file holds it, leaving out values that are the default.
   *
   * @param Definition $definition
   *   The canonical rule.
   *
   * @return array<string, mixed>
   *   The data to encode as YAML.
   */
  public static function toYaml(array $definition): array {
    $data = ['description' => $definition['description']];
    if ($definition['order'] !== NULL) {
      $data['order'] = $definition['order'];
    }
    if (!$definition['enabled']) {
      $data['enabled'] = FALSE;
    }
    $data['action'] = $definition['action'];
    if ($definition['parameters'] !== []) {
      $data['parameters'] = $definition['parameters'];
    }
    if ($definition['match'] !== 'MatchAny') {
      $data['match'] = $definition['match'];
    }
    foreach ($definition['triggers'] as $trigger) {
      $item = ['type' => $trigger['type']];
      if ($trigger['match'] !== 'MatchAny') {
        $item['match'] = $trigger['match'];
      }
      $item['patterns'] = $trigger['patterns'];
      if ($trigger['parameter'] !== '') {
        $item['parameter'] = $trigger['parameter'];
      }
      $data['triggers'][] = $item;
    }
    foreach ($definition['extra_actions'] as $action) {
      $data['extra_actions'][] = $action['parameters'] === [] ? ['action' => $action['action']] : $action;
    }
    return $data;
  }

  /**
   * Whether a rule on bunny.net is what a file describes.
   *
   * @param Definition $file
   *   The rule file's definition.
   * @param Definition $remote
   *   The rule bunny.net has, from fromApi().
   * @param array<mixed> $rule
   *   The same rule as the API returned it, for its order.
   */
  public static function matches(array $file, array $remote, array $rule): bool {
    return array_replace($file, ['order' => NULL]) === $remote
      && ($file['order'] === NULL || $file['order'] === self::orderOf($rule));
  }

  /**
   * A rule's place, as the API returned it.
   *
   * @param array<mixed> $rule
   *   An EdgeRuleV2Model.
   */
  public static function orderOf(array $rule): ?int {
    return is_numeric($rule['OrderIndex'] ?? NULL) ? (int) $rule['OrderIndex'] : NULL;
  }

  /**
   * The file name in a managed rule's description, or NULL.
   */
  public static function managedName(string $description): ?string {
    return preg_match(self::MARKER, $description, $match) === 1 ? $match[1] : NULL;
  }

  /**
   * Whether a string can name a rule file.
   */
  public static function isValidName(string $name): bool {
    return preg_match('/^[A-Za-z0-9_.-]+$/', $name) === 1;
  }

  /**
   * A description without its marker.
   */
  private static function withoutMarker(string $description): string {
    return (string) preg_replace(self::MARKER, '', $description);
  }

  /**
   * An enum case's name from a file, checked; a number passes as it is.
   *
   * @param class-string<\BackedEnum> $enum
   *   The enum.
   * @param mixed $value
   *   The value in the file.
   * @param string $source
   *   The file, for error messages.
   * @param string $where
   *   The key, for error messages.
   */
  private static function enumName(string $enum, mixed $value, string $source, string $where): string|int {
    if (is_int($value)) {
      return self::nameOf($enum, $value);
    }
    // constant() also resolves the aliases the toolkit keeps for renamed cases.
    if (is_string($value) && preg_match('/^\w+$/', $value) === 1 && defined($enum . '::' . $value)) {
      $case = constant($enum . '::' . $value);
      if ($case instanceof $enum) {
        return $case->name;
      }
    }
    $names = array_map(static fn(\BackedEnum $case): string => $case->name, $enum::cases());
    throw self::invalid($source, sprintf('%s must be one of %s', $where, implode(', ', $names)));
  }

  /**
   * An API value's case name, or the number when the enum doesn't know it.
   *
   * @param class-string<\BackedEnum> $enum
   *   The enum.
   * @param mixed $value
   *   The value from the API.
   */
  private static function nameOf(string $enum, mixed $value): string|int {
    $value = is_numeric($value) ? (int) $value : 0;
    foreach ($enum::cases() as $case) {
      if ($case->value === $value) {
        return $case->name;
      }
    }
    return $value;
  }

  /**
   * The API value for a case name or number.
   *
   * @param class-string<\BackedEnum> $enum
   *   The enum.
   * @param string|int $name
   *   The case name, or a number the enum doesn't know.
   */
  private static function valueOf(string $enum, string|int $name): int|string {
    if (is_int($name)) {
      return $name;
    }
    $case = constant($enum . '::' . $name);
    assert($case instanceof \BackedEnum);
    return $case->value;
  }

  /**
   * Action parameters, without the empty ones at the end.
   *
   * @return list<string>
   *   The parameters.
   */
  private static function parameters(mixed $value, string $source, string $where): array {
    $parameters = self::strings($value, $source, $where);
    if (count($parameters) > self::MAX_PARAMETERS) {
      throw self::invalid($source, sprintf('%s takes at most %d values', $where, self::MAX_PARAMETERS));
    }
    return self::trimEnd($parameters);
  }

  /**
   * ActionParameter1-3 from the API, without the empty ones at the end.
   *
   * @param array<mixed> $item
   *   A rule or extra action.
   *
   * @return list<string>
   *   The parameters.
   */
  private static function apiParameters(array $item): array {
    $parameters = [];
    for ($i = 1; $i <= self::MAX_PARAMETERS; $i++) {
      $value = $item['ActionParameter' . $i] ?? '';
      $parameters[] = is_scalar($value) ? (string) $value : '';
    }
    return self::trimEnd($parameters);
  }

  /**
   * Drops empty strings from the end of a list.
   *
   * @param list<string> $values
   *   The list.
   *
   * @return list<string>
   *   The list without trailing empty strings.
   */
  private static function trimEnd(array $values): array {
    while ($values !== [] && end($values) === '') {
      array_pop($values);
    }
    return $values;
  }

  /**
   * A list of strings from a file; numbers are taken as strings.
   *
   * @return list<string>
   *   The strings.
   */
  private static function strings(mixed $value, string $source, string $where): array {
    if (is_scalar($value)) {
      $value = [$value];
    }
    if (!is_array($value) || !array_is_list($value)) {
      throw self::invalid($source, $where . ' must be a list');
    }
    return array_map(static fn(mixed $item): string => self::string($item, $source, $where), $value);
  }

  /**
   * A string from a file; numbers are taken as strings.
   */
  private static function string(mixed $value, string $source, string $where): string {
    if (is_string($value) || is_int($value) || is_float($value)) {
      return (string) $value;
    }
    throw self::invalid($source, $where . ' must be text');
  }

  /**
   * An error for an invalid rule file.
   */
  private static function invalid(string $source, string $message): \InvalidArgumentException {
    return new \InvalidArgumentException(sprintf('%s: %s.', $source, $message));
  }

}
