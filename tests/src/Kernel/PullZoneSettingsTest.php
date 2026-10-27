<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\bunny_net\PullZone\PullZoneSettingChange;
use Drupal\bunny_net\PullZone\PullZoneSettings;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Site\Settings;
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
      static fn(PullZoneSettingChange $change): array => [
        $change->operation,
        $change->key,
        $change->current,
        $change->configured,
      ],
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
   * Without a file at the error page's place, nothing is sent for it.
   */
  public function testNoErrorPage(): void {
    $this->config('bunny_net.settings')->set('pull_zone_settings', [])->save();

    $this->assertSame([], $this->settings()->errorPage());
    $this->assertFalse($this->settings()->isConfigured());
    $this->assertStringEndsWith('/../bunny/error-page.html', $this->settings()->errorPagePath());
  }

  /**
   * The error page file is sent as bunny.net's custom error page.
   */
  public function testErrorPage(): void {
    $this->config('bunny_net.settings')->set('pull_zone_settings', [])->save();
    $this->errorPageAt(dirname(__DIR__, 3) . '/examples/error-page.html');
    $this->answerJson([
      'Id' => self::PULL_ZONE,
      'ErrorPageEnableCustomCode' => FALSE,
      'ErrorPageCustomCode' => NULL,
      'ErrorPageWhitelabel' => FALSE,
    ]);

    $changes = $this->settings()->plan();

    $keys = array_map(static fn(PullZoneSettingChange $change): string => $change->key, $changes);
    $this->assertSame(['ErrorPageEnableCustomCode', 'ErrorPageCustomCode', 'ErrorPageWhitelabel'], $keys);
    $this->assertStringContainsString('{{status_code}}', (string) $changes[1]->value);
    $this->assertTrue($changes[2]->value, "Without bunny.net's name.");
    $this->assertTrue($this->settings()->isConfigured());
  }

  /**
   * An empty error page stops the sync.
   */
  public function testEmptyErrorPage(): void {
    file_put_contents($this->siteDirectory . '/empty.html', ' ');
    $this->errorPageAt($this->siteDirectory . '/empty.html');

    $this->expectExceptionMessage('empty.html is empty.');
    $this->settings()->values();
  }

  /**
   * The error page can't also be set in pull_zone_settings.
   */
  public function testErrorPageInBothPlaces(): void {
    $this->config('bunny_net.settings')->set('pull_zone_settings', ['ErrorPageWhitelabel' => FALSE])->save();
    $this->errorPageAt(dirname(__DIR__, 3) . '/examples/error-page.html');

    $this->expectExceptionMessage('pull_zone_settings sets ErrorPageWhitelabel, which the error page');
    $this->settings()->values();
  }

  /**
   * A new install has the recommended settings, as the list has them.
   */
  public function testInstalledWithTheRecommendations(): void {
    $install = (new FileStorage(dirname(__DIR__, 3) . '/config/install'))->read('bunny_net.settings');
    $recommended = array_map(static fn(array $recommendation): bool => $recommendation[0], PullZoneSettings::RECOMMENDED);

    $this->assertSame($recommended, is_array($install) ? $install['pull_zone_settings'] : NULL);
  }

  /**
   * The recommended settings are ones the API takes, with their reasons.
   */
  public function testRecommended(): void {
    foreach (PullZoneSettings::RECOMMENDED as $key => [$value, $reason]) {
      $this->assertSame($value, PullZoneSettings::toApi($key, $value), $key);
      $this->assertNotSame('', $reason);
    }
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
   * Names the error page in settings.php, as a site would.
   */
  private function errorPageAt(string $path): void {
    $this->setSetting('bunny_net', ['api_key' => 'test-key', 'error_page' => $path]);
    // Services built since setUp() hold the Settings from before.
    $this->container->set('settings', Settings::getInstance());
    $this->container->set('bunny_net.pull_zone_settings', NULL);
  }

  /**
   * The service.
   */
  private function settings(): PullZoneSettings {
    return $this->container->get('bunny_net.pull_zone_settings');
  }

}
