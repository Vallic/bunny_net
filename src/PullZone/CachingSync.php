<?php

declare(strict_types=1);

namespace Drupal\bunny_net\PullZone;

use Drupal\bunny_net\BunnyClientFactory;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Valicm\BunnyToolkit\Caching\CachingChoices;
use Valicm\BunnyToolkit\Caching\SessionCookies;
use Valicm\BunnyToolkit\Caching\VaryCache;
use Valicm\BunnyToolkit\Caching\ZoneCaching;

/**
 * Keeps a pull zone's caching in line with `caching` in bunny_net.settings.
 *
 * The choices become pull zone settings (Smart Cache, the varies, blocked
 * countries) and Edge Rules, written by the bunny-toolkit's ZoneCaching:
 *
 * - responses Drupal marks private are never cached;
 * - visitors with a Drupal session, or a cookie named here, get pages from
 *   Drupal rather than the cache; static files are still cached for them;
 * - the paths listed are never cached;
 * - browsers keep pages and files for the times chosen, never a private
 *   response, and never a page for a visitor with a session.
 *
 * The rules' descriptions start with "bunny_net: ", which is how a sync
 * finds them again; other rules on the zone are left alone. They take the
 * order indexes FIRST_ORDER to LAST_ORDER, and a sync stops when another
 * rule holds one of them.
 */
final class CachingSync {

  /**
   * What starts each caching rule's description.
   */
  public const string PREFIX = 'bunny_net: ';

  /**
   * The first of the order indexes the caching rules take.
   */
  public const int FIRST_ORDER = 20;

  /**
   * The last of them.
   */
  public const int LAST_ORDER = 45;

  public function __construct(
    private readonly BunnyClientFactory $clientFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether this site manages the pull zone's caching.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('bunny_net.settings')->get('caching.enabled');
  }

  /**
   * The choices in config.
   */
  public function choices(): CachingChoices {
    $config = $this->configFactory->get('bunny_net.settings');
    $list = static function (string $key) use ($config): array {
      $value = $config->get('caching.' . $key);
      return is_array($value) ? array_values(array_map(strval(...), array_filter($value, is_scalar(...)))) : [];
    };
    $time = static function (string $key) use ($config): ?int {
      $seconds = (int) $config->get('caching.' . $key);
      return $seconds > 0 ? $seconds : NULL;
    };
    return new CachingChoices(
      dynamic: (bool) $config->get('caching.dynamic'),
      vary: VaryCache::some($list('vary')),
      varyHeaders: $list('vary_headers'),
      varyCookies: $list('vary_cookies'),
      blockedCountries: $list('blocked_countries'),
      skipPaths: $list('skip_paths'),
      sessionCookies: $list('session_cookies'),
      browserPages: $time('browser_pages'),
      browserFiles: $time('browser_files'),
    );
  }

  /**
   * Settings in pull_zone_settings that caching sets as well.
   *
   * Both syncs would send them, each its own value, so they can't be kept
   * in both.
   *
   * @return list<string>
   *   API names.
   */
  public function conflicts(): array {
    $configured = $this->configFactory->get('bunny_net.settings')->get('pull_zone_settings');
    if (!$this->isEnabled() || !is_array($configured)) {
      return [];
    }
    return array_values(array_intersect(array_keys($this->caching()->properties($this->choices())), array_map(strval(...), array_keys($configured))));
  }

  /**
   * Compares the configured caching with the pull zone.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When caching is off, or the pull zone or API key is missing.
   * @throws \InvalidArgumentException
   *   When pull_zone_settings holds a setting caching sets.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function plan(): CachingPlan {
    if (!$this->isEnabled()) {
      throw new NotConfiguredException('Caching is not managed here: set caching.enabled in bunny_net.settings.');
    }
    $conflicts = $this->conflicts();
    if ($conflicts !== []) {
      throw new \InvalidArgumentException(sprintf('pull_zone_settings also sets %s, which caching sets: keep them in one place.', implode(', ', $conflicts)));
    }
    $zone = $this->clientFactory->get()->core()->pullZone()->getPullZone($this->pullZoneId());
    $zone = is_array($zone) ? $zone : [];
    $taken = $this->taken($zone);
    if ($taken !== []) {
      throw new \InvalidArgumentException(sprintf('Other rules hold the caching rules\' places (%d to %d), and bunny.net refuses two rules at one: %s. Give them another order.', self::FIRST_ORDER, self::LAST_ORDER, implode(', ', $taken)));
    }
    $choices = $this->choices();
    $settings = [];
    foreach ($this->caching()->properties($choices) as $key => $value) {
      $current = $zone[$key] ?? NULL;
      if (!self::same($current, $value)) {
        $settings[$key] = ['current' => $current, 'wanted' => $value];
      }
    }
    return new CachingPlan($settings, $this->caching()->plan($zone, $choices, SessionCookies::DRUPAL));
  }

  /**
   * Sends a plan: the settings in one update, then the rules.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When the pull zone or API key is missing.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects a request; the ones before it stand.
   */
  public function apply(CachingPlan $plan): void {
    $core = $this->clientFactory->get()->core();
    $settings = array_map(static fn(array $change): mixed => $change['wanted'], $plan->settings);
    if ($settings !== []) {
      $core->pullZone()->updatePullZone($this->pullZoneId(), $settings);
    }
    $plan->rules->apply($core, $this->pullZoneId());
  }

  /**
   * Other rules on the zone at the caching rules' places.
   *
   * @param array<mixed> $zone
   *   The pull zone, as bunny.net returns it.
   *
   * @return list<string>
   *   Their descriptions and places.
   */
  private function taken(array $zone): array {
    $taken = [];
    foreach (is_array($zone['EdgeRules'] ?? NULL) ? $zone['EdgeRules'] : [] as $rule) {
      $description = is_array($rule) && is_string($rule['Description'] ?? NULL) ? $rule['Description'] : '';
      $order = is_array($rule) && is_numeric($rule['OrderIndex'] ?? NULL) ? (int) $rule['OrderIndex'] : NULL;
      if ($order !== NULL && $order >= self::FIRST_ORDER && $order <= self::LAST_ORDER && !str_starts_with($description, self::PREFIX)) {
        $taken[] = sprintf('"%s" at %d', $description, $order);
      }
    }
    return $taken;
  }

  /**
   * The rules, under this module's descriptions.
   */
  public function caching(): ZoneCaching {
    return new ZoneCaching(self::PREFIX);
  }

  /**
   * Whether the pull zone's value equals the wanted one.
   *
   * The API returns NULL for empty strings and lists.
   */
  private static function same(mixed $current, mixed $wanted): bool {
    $empty = [NULL, '', [], FALSE];
    if (in_array($current, $empty, TRUE) || in_array($wanted, $empty, TRUE)) {
      return in_array($current, $empty, TRUE) && in_array($wanted, $empty, TRUE);
    }
    return $current === $wanted;
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
