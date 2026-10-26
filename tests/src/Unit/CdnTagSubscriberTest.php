<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Unit;

use Drupal\bunny_net\CacheTags;
use Drupal\bunny_net\EventSubscriber\CdnTagSubscriber;
use Drupal\cache_purifier\OutboundTags;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;

/**
 * Responses carry their cache tags as bunny.net reads them.
 */
#[Group('bunny_net')]
#[CoversClass(CdnTagSubscriber::class)]
#[CoversClass(CacheTags::class)]
final class CdnTagSubscriberTest extends UnitTestCase {

  /**
   * Cache Purifier's blocklist applies; the rest is prefixed and hashed.
   */
  public function testHeader(): void {
    $tags = ['node:1', 'rendered', 'config:system.site', 'node_list'];
    $response = $this->respond($tags, ['prefix' => 'rum:'], ['rendered', 'config:*']);

    $expected = (new HashedTagList())->header(['rum:node_list', 'rum:node:1'])->value;
    $this->assertSame($expected, $response->headers->get('CDN-Tag'));
  }

  /**
   * Cache Purifier's order is kept: list, entity, config, then other tags.
   */
  public function testTagOrder(): void {
    $response = $this->respond([
      'auction_view',
      'config:block_list',
      'node:1',
      'config:system.site',
      'search_api_list:lots',
      'local_task',
      'media:7',
      'node_list',
      'node_list:article',
    ], ['prefix' => 'rum:']);

    $expected = (new HashedTagList())->header([
      'rum:search_api_list:lots',
      'rum:node_list',
      'rum:node_list:article',
      'rum:node:1',
      'rum:media:7',
      'rum:config:block_list',
      'rum:config:system.site',
      'rum:auction_view',
      'rum:local_task',
    ])->value;
    $this->assertSame($expected, $response->headers->get('CDN-Tag'));
  }

  /**
   * No header when it is off, all tags are blocklisted, or it isn't public.
   */
  public function testNoHeader(): void {
    $this->assertFalse($this->respond(['node:1'], ['header' => FALSE])->headers->has('CDN-Tag'));
    $this->assertFalse($this->respond(['rendered'], [])->headers->has('CDN-Tag'));
    $this->assertFalse($this->respond(['node:1'], [], public: FALSE)->headers->has('CDN-Tag'));
  }

  /**
   * Runs the subscriber on a response carrying these tags.
   *
   * @param list<string> $tags
   *   The response's cache tags.
   * @param array<string, mixed> $settings
   *   Overrides for bunny.net's tags settings.
   * @param list<string> $blocklist
   *   Cache Purifier's outbound blocklist.
   * @param bool $public
   *   Whether the response is public, as Drupal marks cacheable pages.
   */
  private function respond(array $tags, array $settings, array $blocklist = ['rendered'], bool $public = TRUE): Response {
    $configFactory = $this->getConfigFactoryStub([
      'bunny_net.settings' => [
        'tags' => $settings + ['header' => TRUE, 'prefix' => ''],
      ],
      'cache_purifier.settings' => [
        'outbound' => ['blocklist' => $blocklist],
      ],
    ]);
    $response = new CacheableResponse();
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags($tags));
    if ($public) {
      $response->setPublic();
    }
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $response);

    (new CdnTagSubscriber(new CacheTags($configFactory, new OutboundTags($configFactory))))->onResponse($event);
    return $response;
  }

}
