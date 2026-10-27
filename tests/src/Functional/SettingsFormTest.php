<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Functional;

use Drupal\bunny_net\Form\SettingsForm;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The settings form saves settings and shows Edge Scripts and Rules.
 */
#[Group('bunny_net')]
#[CoversClass(SettingsForm::class)]
#[RunTestsInSeparateProcesses]
final class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['bunny_net'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Settings, scripts and rules are listed from config, without secrets.
   */
  public function testEdgeOverview(): void {
    $fixtures = dirname(__DIR__, 2) . '/fixtures';
    $this->config('bunny_net.settings')
      ->set('edge_scripts', [
        'middleware' => [
          'script_id' => 77,
          'file' => $fixtures . '/scripts/middleware.ts',
          'variables' => ['ORIGIN' => 'www.example.com'],
        ],
        'gone' => ['script_id' => 78, 'file' => $fixtures . '/scripts/gone.ts'],
      ])
      ->set('edge_rules.directory', $fixtures . '/missing-rules')
      ->set('pull_zone_settings', ['CookieVaryParameters' => ['my_role'], 'EnableCookieVaryy' => TRUE])
      ->save();
    $this->writeSettings([
      'settings' => [
        'bunny_net' => [
          'edge_script_secrets' => (object) [
            'value' => ['middleware' => ['SIGNING_KEY' => 's3cret']],
            'required' => TRUE,
          ],
        ],
      ],
    ]);

    $this->drupalLogin($this->drupalCreateUser(['administer bunny_net']));
    $this->drupalGet('admin/config/services/bunny');
    $this->assertSession()->titleEquals('Bunny CDN | Drupal');
    $this->container->get('queue')->get('bunny_net_purge')->createItem(['type' => 'tags', 'items' => ['node:1']]);
    $this->drupalGet('admin/config/services/bunny');
    $this->assertSession()->pageTextContains('1 purge waiting.');
    $this->assertSession()->pageTextContains('drush queue:run bunny_net_purge');
    $this->assertSession()->pageTextContains('middleware 77');
    $this->assertSession()->pageTextContains('ORIGIN');
    $this->assertSession()->pageTextContains('SIGNING_KEY');
    $this->assertSession()->pageTextNotContains('s3cret');
    $this->assertSession()->pageTextContains('gone.ts (not found)');
    $this->assertSession()->pageTextContains('missing-rules does not exist.');
    $this->assertSession()->pageTextContains('CookieVaryParameters ["my_role"]');
    $this->assertSession()->pageTextContains('EnableCookieVaryy is not a pull zone setting. Did you mean EnableCookieVary?');

    $this->submitForm(['pull_zone_id' => 4321], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame(4321, $this->config('bunny_net.settings')->get('pull_zone_id'));
    $this->assertCount(2, $this->config('bunny_net.settings')->get('edge_scripts'), 'Scripts are left as they were.');
    $this->assertCount(2, $this->config('bunny_net.settings')->get('pull_zone_settings'), 'Pull zone settings are left as they were.');

    $this->config('bunny_net.settings')->set('edge_rules.directory', $fixtures . '/rules')->save();
    $this->drupalGet('admin/config/services/bunny');
    $this->assertSession()->pageTextContains('rules: 2 rules.');
  }

  /**
   * Caching is chosen on the form and saved as lists in config.
   */
  public function testCaching(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer bunny_net']));
    $this->drupalGet('admin/config/services/bunny');

    $this->submitForm([
      'caching[enabled]' => TRUE,
      'caching[skip_paths]' => "/cart/*\n/",
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('These are not paths that can skip the cache: /.');

    $this->submitForm([
      'caching[enabled]' => TRUE,
      'caching[vary][webp]' => TRUE,
      'caching[skip_paths]' => "/cart/*\n\n/checkout ",
      'caching[session_cookies]' => 'shop_session',
      'caching[browser_pages]' => '900',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $caching = $this->config('bunny_net.settings')->get('caching');
    $this->assertTrue($caching['enabled']);
    $this->assertSame(['webp'], $caching['vary']);
    $this->assertSame(['/cart/*', '/checkout'], $caching['skip_paths']);
    $this->assertSame(['shop_session'], $caching['session_cookies']);
    $this->assertSame(900, $caching['browser_pages']);
    $this->assertSame(0, $caching['browser_files']);
    $this->assertSession()->fieldValueEquals('caching[skip_paths]', "/cart/*\n/checkout");
  }

}
