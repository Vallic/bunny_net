<?php

declare(strict_types=1);

namespace Drupal\bunny_net\PullZone;

use Drupal\bunny_net\BunnyClientFactory;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Keeps a pull zone's settings in line with pull_zone_settings in config.
 *
 * Keys are bunny.net's own API names, such as EnableCookieVary, so every
 * setting the API can update can be kept in config. Enum settings take the
 * toolkit's case names, or a number. Only the configured settings are
 * compared and sent; the rest of the pull zone is left as it is.
 *
 * @code
 * pull_zone_settings:
 *   EnableCookieVary: true
 *   CookieVaryParameters: [my_role]
 *   UseStaleWhileUpdating: true
 *   LogFormat: Json
 * @endcode
 *
 * The page bunny.net shows when the origin can't answer is kept as an HTML
 * file, error_page.file, and sent with these settings.
 */
final class PullZoneSettings {

  /**
   * Settings a Drupal site behind bunny.net wants, and why.
   *
   * `drush bunny:zone-recommend` prints them, or saves them as
   * pull_zone_settings.
   */
  public const array RECOMMENDED = [
    'AddHostHeader' => [
      TRUE,
      "Drupal builds links and checks trusted_host_patterns from the Host header; without it, Drupal sees the origin's name.",
    ],
    'FollowRedirects' => [
      FALSE,
      'Followed at the edge, Drupal\'s redirects (http to https, the Redirect module, language prefixes) serve the page under the wrong URL.',
    ],
    'DisableCookies' => [
      FALSE,
      'Strips Set-Cookie from responses, so nobody can log in through the CDN. New pull zones have it on.',
    ],
    'CacheErrorResponses' => [
      FALSE,
      'With stale caching on, one cached 503 can be served long after the site has recovered.',
    ],
    'IgnoreQueryStrings' => [
      FALSE,
      'Pagers (?page=2) and search results would all get the first cached page.',
    ],
    'UseStaleWhileUpdating' => [
      TRUE,
      'Visitors get the cached page while bunny.net fetches a fresh one.',
    ],
    'UseStaleWhileOffline' => [
      TRUE,
      'Visitors get the cached page instead of an error while the site is down.',
    ],
  ];

  /**
   * The settings the error page file is sent as.
   */
  public const array ERROR_PAGE = ['ErrorPageEnableCustomCode', 'ErrorPageCustomCode', 'ErrorPageWhitelabel'];

  public function __construct(
    private readonly BunnyClientFactory $clientFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly string $appRoot,
  ) {}

  /**
   * Whether there is anything to sync: settings, or an error page.
   */
  public function isConfigured(): bool {
    return $this->configured() !== [] || $this->errorPageFile() !== '';
  }

  /**
   * The error page file, as configured, or an empty string.
   */
  public function errorPageFile(): string {
    return trim((string) $this->configFactory->get('bunny_net.settings')->get('error_page.file'));
  }

  /**
   * The error page file's path, relative ones from the Drupal root.
   */
  public function errorPagePath(): string {
    $file = $this->errorPageFile();
    return $file === '' || str_starts_with($file, '/') || str_contains($file, '://') ? $file : $this->appRoot . '/' . $file;
  }

  /**
   * The settings the error page is sent as, or none without a file.
   *
   * @return array<string, bool|string>
   *   Values by API name.
   *
   * @throws \InvalidArgumentException
   *   When the file can't be read, or pull_zone_settings sets them too.
   */
  public function errorPage(): array {
    $path = $this->errorPagePath();
    if ($path === '') {
      return [];
    }
    $both = array_intersect(self::ERROR_PAGE, array_map(strval(...), array_keys($this->configured())));
    if ($both !== []) {
      throw new \InvalidArgumentException(sprintf('pull_zone_settings sets %s, which error_page.file sets: keep them in one place.', implode(', ', $both)));
    }
    $code = is_file($path) ? file_get_contents($path) : FALSE;
    if (!is_string($code) || trim($code) === '') {
      throw new \InvalidArgumentException(sprintf('The error page %s is missing or empty.', $path));
    }
    return [
      'ErrorPageEnableCustomCode' => TRUE,
      'ErrorPageCustomCode' => $code,
      'ErrorPageWhitelabel' => (bool) $this->configFactory->get('bunny_net.settings')->get('error_page.whitelabel'),
    ];
  }

  /**
   * The settings in config, as written there.
   *
   * @return array<string, mixed>
   *   Values by API name.
   */
  public function configured(): array {
    $settings = $this->configFactory->get('bunny_net.settings')->get('pull_zone_settings');
    return is_array($settings) ? $settings : [];
  }

  /**
   * The settings in config, checked, as the API takes them.
   *
   * The error page's included.
   *
   * @return array<string, mixed>
   *   Values by API name.
   *
   * @throws \InvalidArgumentException
   *   With every invalid setting, one per line.
   */
  public function values(): array {
    $values = [];
    $errors = [];
    foreach ($this->configured() as $key => $value) {
      try {
        $values[(string) $key] = self::toApi((string) $key, $value);
      }
      catch (\InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
      }
    }
    try {
      $values += $this->errorPage();
    }
    catch (\InvalidArgumentException $e) {
      $errors[] = $e->getMessage();
    }
    if ($errors !== []) {
      throw new \InvalidArgumentException(implode("\n", $errors));
    }
    return $values;
  }

  /**
   * Compares the configured settings with the pull zone.
   *
   * @return list<\Drupal\bunny_net\PullZone\PullZoneSettingChange>
   *   One entry per configured setting, unchanged ones included.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When the pull zone or API key is missing.
   * @throws \InvalidArgumentException
   *   When a configured setting is invalid.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function plan(): array {
    $values = $this->values();
    if ($values === []) {
      return [];
    }
    $zone = $this->zone();
    $changes = [];
    foreach ($values as $key => $value) {
      $readable = array_key_exists($key, $zone);
      $operation = match (TRUE) {
        !$readable => PullZoneSettingChange::UNREADABLE,
        self::same($zone[$key], $value) => PullZoneSettingChange::UNCHANGED,
        default => PullZoneSettingChange::UPDATE,
      };
      $current = $readable ? self::fromApi($key, $zone[$key]) : NULL;
      $changes[] = new PullZoneSettingChange($operation, $key, $current, self::fromApi($key, $value), $value);
    }
    return $changes;
  }

  /**
   * Sends the changed settings in one update.
   *
   * @param list<\Drupal\bunny_net\PullZone\PullZoneSettingChange> $changes
   *   Changes from plan().
   *
   * @return list<string>
   *   Settings bunny.net returned with another value than was sent, so
   *   it ignored or adjusted them.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When the pull zone or API key is missing.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function apply(array $changes): array {
    $body = [];
    foreach ($changes as $change) {
      if ($change->isChange()) {
        $body[$change->key] = $change->value;
      }
    }
    if ($body === []) {
      return [];
    }
    $zone = $this->clientFactory->get()->core()->pullZone()->updatePullZone($this->pullZoneId(), $body);
    $zone = is_array($zone) ? $zone : [];
    return array_values(array_filter(
      array_keys($body),
      static fn(string $key): bool => array_key_exists($key, $zone) && !self::same($zone[$key], $body[$key]),
    ));
  }

  /**
   * The pull zone's settings, as config would hold them.
   *
   * @param list<string> $keys
   *   The settings to read; every readable one when empty.
   *
   * @return array<string, mixed>
   *   Values by API name, in the API's order, without credentials.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When the pull zone or API key is missing.
   * @throws \InvalidArgumentException
   *   When a key is not a pull zone setting.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function export(array $keys = []): array {
    foreach ($keys as $key) {
      self::field($key);
    }
    $zone = $this->zone();
    $export = [];
    foreach (array_keys(PullZoneFields::all()) as $key) {
      if (($keys === [] || in_array($key, $keys, TRUE)) && array_key_exists($key, $zone) && !in_array($key, PullZoneFields::SECRETS, TRUE)) {
        $export[$key] = self::fromApi($key, $zone[$key]);
      }
    }
    return $export;
  }

  /**
   * A configured value as the API takes it.
   *
   * @throws \InvalidArgumentException
   *   When the key is not a setting or the value has the wrong type.
   */
  public static function toApi(string $key, mixed $value): mixed {
    $field = self::field($key);
    if ($field['enum'] !== NULL) {
      return self::enumValue($key, $field['enum'], $value);
    }
    $valid = match ($field['type']) {
      'bool' => is_bool($value),
      'int' => is_int($value),
      'float' => is_int($value) || is_float($value),
      'string' => is_string($value),
      'list' => is_array($value) && array_is_list($value),
      default => is_array($value),
    };
    if (!$valid) {
      throw new \InvalidArgumentException(sprintf('%s must be %s.', $key, match ($field['type']) {
        'bool' => 'true or false',
        'int' => 'a whole number',
        'float' => 'a number',
        'string' => 'a string',
        'list' => 'a list',
        default => 'a mapping',
      }));
    }
    return $value;
  }

  /**
   * An API value as config holds it: enum settings by case name.
   */
  public static function fromApi(string $key, mixed $value): mixed {
    $enum = PullZoneFields::all()[$key]['enum'] ?? NULL;
    // Compared case by case: tryFrom() throws on a string for an int enum.
    foreach ($enum !== NULL ? $enum::cases() : [] as $case) {
      if ($case->value === $value) {
        return $case->name;
      }
    }
    return $value;
  }

  /**
   * A setting's field, refusing credentials and unknown keys.
   *
   * @return array{type: string, enum: class-string<\BackedEnum>|null}
   *   The field.
   *
   * @throws \InvalidArgumentException
   *   When the key is not a setting config may hold.
   */
  private static function field(string $key): array {
    if (in_array($key, PullZoneFields::SECRETS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('%s holds a credential: set it in the bunny.net dashboard, not in exported config.', $key));
    }
    $fields = PullZoneFields::all();
    if (isset($fields[$key])) {
      return $fields[$key];
    }
    $closest = NULL;
    // A typo or two in a long name; short names are too alike to guess.
    $distance = min(4, intdiv(strlen($key), 4) + 1);
    foreach (array_keys($fields) as $name) {
      $d = levenshtein(strtolower($key), strtolower($name));
      if ($d < $distance) {
        [$closest, $distance] = [$name, $d];
      }
    }
    throw new \InvalidArgumentException(sprintf('%s is not a pull zone setting.%s', $key, $closest !== NULL ? sprintf(' Did you mean %s?', $closest) : ''));
  }

  /**
   * An enum setting's API value, from a case name or a number.
   *
   * A number bunny.net added after the toolkit was generated passes as it is.
   *
   * @param string $key
   *   The setting, for the error message.
   * @param class-string<\BackedEnum> $enum
   *   The enum.
   * @param mixed $value
   *   The configured value.
   *
   * @throws \InvalidArgumentException
   *   When the value is neither a case name nor a number.
   */
  private static function enumValue(string $key, string $enum, mixed $value): int|string {
    if (is_int($value)) {
      return $value;
    }
    // constant() also resolves the aliases the toolkit keeps for renamed cases.
    if (is_string($value) && preg_match('/^\w+$/', $value) === 1 && defined($enum . '::' . $value)) {
      $case = constant($enum . '::' . $value);
      if ($case instanceof $enum) {
        return $case->value;
      }
    }
    $names = array_map(static fn(\BackedEnum $case): string => $case->name, $enum::cases());
    throw new \InvalidArgumentException(sprintf('%s must be one of %s.', $key, implode(', ', $names)));
  }

  /**
   * Whether the pull zone's value equals a configured one.
   *
   * The API returns NULL for empty strings and lists, and may return a
   * whole number for a float.
   */
  private static function same(mixed $current, mixed $value): bool {
    if ($current === NULL || $value === NULL) {
      return in_array($current, [NULL, '', []], TRUE) && in_array($value, [NULL, '', []], TRUE);
    }
    if (is_int($current) || is_float($current)) {
      return (is_int($value) || is_float($value)) && (float) $current === (float) $value;
    }
    return $current === $value;
  }

  /**
   * The pull zone, as bunny.net returns it.
   *
   * @return array<string, mixed>
   *   The PullZoneModel.
   */
  private function zone(): array {
    $zone = $this->clientFactory->get()->core()->pullZone()->getPullZone($this->pullZoneId());
    return is_array($zone) ? $zone : [];
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

}
