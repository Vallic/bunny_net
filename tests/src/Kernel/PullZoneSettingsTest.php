<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\bunny_net\PullZone\PullZoneSettingChange;
use Drupal\bunny_net\PullZone\PullZoneSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Syncing pull zone settings sends only the configured ones that differ.
 */
#[Group('bunny_net')]
#[CoversClass(PullZoneSettings::class)]
#[CoversClass(PullZoneSettingChange::class)]
#[RunTestsInSeparateProcesses]
final class PullZoneSettingsTest extends BunnyNetKernelTestBase {

  /**
   * The pull zone as bunny.net returns it.
   */
  private const array ZONE = [
    'Id' => self::PULL_ZONE,
    'EnableCookieVary' => FALSE,
    'CookieVaryParameters' => [],
    'EnableQueryStringOrdering' => TRUE,
    'OriginHostHeader' => NULL,
    'LogFormat' => 0,
    'AWSSigningSecret' => 'secret',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('bunny_net.settings')->set('pull_zone_settings', [
      'EnableCookieVary' => TRUE,
      'CookieVaryParameters' => ['my_role'],
      'EnableQueryStringOrdering' => TRUE,
      'OriginHostHeader' => '',
      'LogFormat' => 'Json',
      'CacheControlBrowserMaxAgeOverride' => 900,
    ])->save();
  }

  /**
   * Changed settings are updated; unreadable ones are always sent.
   */
  public function testPlan(): void {
    $this->answerJson(self::ZONE);

    $changes = $this->settings()->plan();

    $this->assertSame([
      ['update', 'EnableCookieVary', FALSE, TRUE],
      ['update', 'CookieVaryParameters', [], ['my_role']],
      ['unchanged', 'EnableQueryStringOrdering', TRUE, TRUE],
      ['unchanged', 'OriginHostHeader', NULL, ''],
      ['update', 'LogFormat', 'Plain', 'Json'],
      ['send', 'CacheControlBrowserMaxAgeOverride', NULL, 900],
    ], array_map(
      static fn(PullZoneSettingChange $change): array => [$change->operation, $change->key, $change->current, $change->configured],
      $changes,
    ));
    $this->assertSame(1, $changes[4]->value);
  }

  /**
   * One update carries the changes; values bunny.net kept are reported.
   */
  public function testApply(): void {
    $this->answerJson(self::ZONE, ['EnableCookieVary' => TRUE, 'CookieVaryParameters' => ['my_role'], 'LogFormat' => 0]);
    $settings = $this->settings();

    $ignored = $settings->apply($settings->plan());

    $this->assertSame(['LogFormat'], $ignored);
    $this->assertSame([
      'POST /pullzone/' . self::PULL_ZONE,
      [
        'EnableCookieVary' => TRUE,
        'CookieVaryParameters' => ['my_role'],
        'LogFormat' => 1,
        'CacheControlBrowserMaxAgeOverride' => 900,
      ],
    ], $this->sentRequests()[1]);
  }

  /**
   * An invalid setting stops the sync before anything is sent.
   */
  public function testInvalid(): void {
    $this->config('bunny_net.settings')->set('pull_zone_settings.EnableCookieVary', 'yes')->set('pull_zone_settings.Nope', 1)->save();

    try {
      $this->settings()->plan();
      $this->fail('An invalid setting was accepted.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame("EnableCookieVary must be true or false.\nNope is not a pull zone setting.", $e->getMessage());
    }
    $this->assertSame([], $this->sentRequests());
  }

  /**
   * Export writes enum names and leaves credentials out.
   */
  public function testExport(): void {
    $this->answerJson(self::ZONE, self::ZONE);

    $export = $this->settings()->export(['LogFormat', 'EnableCookieVary']);
    $this->assertSame(['EnableCookieVary' => FALSE, 'LogFormat' => 'Plain'], $export);
    $all = $this->settings()->export();
    $this->assertArrayNotHasKey('AWSSigningSecret', $all);
    $this->assertArrayNotHasKey('Id', $all);
    $this->assertFalse($all['EnableCookieVary']);
  }

  /**
   * The service.
   */
  private function settings(): PullZoneSettings {
    return $this->container->get('bunny_net.pull_zone_settings');
  }

}
