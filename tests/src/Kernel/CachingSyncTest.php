<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\bunny_net\NotConfiguredException;
use Drupal\bunny_net\PullZone\CachingPlan;
use Drupal\bunny_net\PullZone\CachingSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The caching in config reaches the pull zone, and only what differs.
 */
#[Group('bunny_net')]
#[CoversClass(CachingSync::class)]
#[CoversClass(CachingPlan::class)]
#[RunTestsInSeparateProcesses]
final class CachingSyncTest extends BunnyNetKernelTestBase {

  /**
   * The sync.
   */
  private function sync(): CachingSync {
    return $this->container->get('bunny_net.caching_sync');
  }

  /**
   * Nothing is planned until caching is managed here.
   */
  public function testOffUntilEnabled(): void {
    $this->expectException(NotConfiguredException::class);
    $this->sync()->plan();
  }

  /**
   * A zone without the rules gets them, and its settings are updated.
   */
  public function testSyncWritesSettingsAndRules(): void {
    $this->config('bunny_net.settings')->set('caching', [
      'enabled' => TRUE,
      'dynamic' => FALSE,
      'vary' => ['webp'],
      'vary_headers' => [],
      'vary_cookies' => [],
      'blocked_countries' => ['RU'],
      'skip_paths' => ['/cart/*'],
      'session_cookies' => [],
      'browser_pages' => 900,
      'browser_files' => 0,
    ])->save();
    $this->answerJson([
      'Id' => self::PULL_ZONE,
      'EnableWebpVary' => FALSE,
      'BlockedCountries' => [],
      'EdgeRules' => [
        ['Guid' => 'mine', 'Description' => 'Redirect old blog', 'OrderIndex' => 1],
      ],
    ]);

    $plan = $this->sync()->plan();

    $this->assertSame([
      'update EnableWebpVary: false -> true',
      'update BlockedCountries: [] -> ["RU"]',
      'create rule bunny_net: never cache what the origin marks private',
      'create rule bunny_net: browser cache time for pages',
      'create rule bunny_net: signed-in visitors skip the cache',
      'create rule bunny_net: paths never cached',
    ], $plan->describe(), 'Settings already as wanted are not sent; the hand-made rule is left alone.');

    $this->answerJson(['Id' => self::PULL_ZONE]);
    $this->answerNoContent(4);
    $this->sync()->apply($plan);

    $sent = $this->sentRequests();
    $this->assertSame('POST /pullzone/4321', $sent[1][0]);
    $this->assertSame(['EnableWebpVary' => TRUE, 'BlockedCountries' => ['RU']], $sent[1][1]);
    $this->assertSame('POST /pullzone/4321/edgerules/addOrUpdate', $sent[2][0]);
    $this->assertSame(['SESS*', '*; SESS*', 'SSESS*', '*; SSESS*'], $sent[4][1]['Triggers'][0]['PatternMatches'], "Drupal's session cookies.");
  }

  /**
   * Another rule at one of the caching rules' places stops the sync.
   */
  public function testOtherRulesInTheCachingPlaces(): void {
    $this->config('bunny_net.settings')->set('caching.enabled', TRUE)->save();
    $this->answerJson([
      'Id' => self::PULL_ZONE,
      'EdgeRules' => [
        [
          'Guid' => 'ours',
          'Description' => CachingSync::PREFIX . 'never cache what the origin marks private',
          'OrderIndex' => 20,
        ],
        ['Guid' => 'theirs', 'Description' => 'Redirect old blog', 'OrderIndex' => 30],
      ],
    ]);

    $this->expectExceptionMessage('"Redirect old blog" at 30');
    $this->sync()->plan();
  }

  /**
   * A setting can't be kept both in pull_zone_settings and in caching.
   */
  public function testConflictsWithPullZoneSettings(): void {
    $this->config('bunny_net.settings')
      ->set('caching.enabled', TRUE)
      ->set('pull_zone_settings', ['EnableCookieVary' => TRUE, 'UseStaleWhileOffline' => TRUE])
      ->save();

    $this->assertSame(['EnableCookieVary'], $this->sync()->conflicts());
    $this->expectException(\InvalidArgumentException::class);
    $this->sync()->plan();
  }

}
