<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\bunny_net\CacheTags;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A site from before Cache Purifier keeps working and is updated to it.
 */
#[Group('bunny_net')]
#[CoversClass(CacheTags::class)]
#[CoversFunction('bunny_net_update_11001')]
#[CoversFunction('bunny_net_update_11003')]
#[RunTestsInSeparateProcesses]
final class UpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Without cache_purifier, as on a site installed before it was required.
   */
  protected static $modules = ['system', 'bunny_net'];

  /**
   * Nothing is tagged or purged until the update installs Cache Purifier.
   */
  public function testUpdate(): void {
    $this->installConfig(['bunny_net']);
    // The settings as an older version stored them; the key is no longer in
    // the schema, so they go to storage directly.
    $storage = $this->container->get('config.storage');
    $old = $storage->read('bunny_net.settings');
    $this->assertIsArray($old);
    $old['tags']['blocklist'] = ['rendered', 'commerce_order:*'];
    $storage->write('bunny_net.settings', $old);
    $this->container->get('config.factory')->reset('bunny_net.settings');

    $cacheTags = $this->container->get('bunny_net.cache_tags');
    $this->assertInstanceOf(CacheTags::class, $cacheTags);
    $this->assertTrue($cacheTags->header(['node:1'])->isEmpty());
    $this->assertSame([], $cacheTags->filter(['node:1']));

    $this->container->get('module_handler')->loadInclude('bunny_net', 'install');
    bunny_net_update_11001();

    $this->assertTrue($this->container->get('module_handler')->moduleExists('cache_purifier'));
    $this->assertSame(
      ['http_response', 'rendered', 'library_info', 'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:*', 'commerce_order:*'],
      $this->config('cache_purifier.settings')->get('outbound.blocklist'),
    );
    $this->assertNull($this->config('bunny_net.settings')->get('tags.blocklist'));

    $cacheTags = $this->container->get('bunny_net.cache_tags');
    $this->assertInstanceOf(CacheTags::class, $cacheTags);
    $this->assertSame(['node:1'], $cacheTags->filter(['node:1', 'commerce_order:5']));
  }

  /**
   * A site from before caching settings gets them, off, as a new site has.
   */
  public function testCachingUpdate(): void {
    $this->installConfig(['bunny_net']);
    $installed = $this->config('bunny_net.settings')->get('caching');
    $this->config('bunny_net.settings')->clear('caching')->save();

    $this->container->get('module_handler')->loadInclude('bunny_net', 'install');
    bunny_net_update_11003();

    $this->assertSame($installed, $this->config('bunny_net.settings')->get('caching'));
    $this->assertFalse($installed['enabled']);
  }

}
