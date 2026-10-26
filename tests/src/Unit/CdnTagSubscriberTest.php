<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Unit;

use Drupal\bunny_net\CacheTags;
use Drupal\bunny_net\EventSubscriber\CdnTagSubscriber;
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
   * Blocklisted tags are left out, the rest prefixed and hashed.
   */
  public function testHeader(): void {
    $response = $this->respond(['node:1', 'rendered', 'config:system.site', 'node_list'], [
      'prefix' => 'rum:',
      'blocklist' => ['rendered', 'config:*'],
    ]);

    $expected = (new HashedTagList())->header(['rum:node:1', 'rum:node_list'])->value;
    $this->assertSame($expected, $response->headers->get('CDN-Tag'));
  }

  /**
   * No header when it is turned off, or when every tag is blocklisted.
   */
  public function testNoHeader(): void {
    $this->assertFalse($this->respond(['node:1'], ['header' => FALSE])->headers->has('CDN-Tag'));
    $this->assertFalse($this->respond(['rendered'], [])->headers->has('CDN-Tag'));
  }

  /**
   * Runs the subscriber on a response carrying these tags.
   *
   * @param list<string> $tags
   *   The response's cache tags.
   * @param array<string, mixed> $settings
   *   Overrides for the tags settings.
   */
  private function respond(array $tags, array $settings): Response {
    $configFactory = $this->getConfigFactoryStub([
      'bunny_net.settings' => [
        'tags' => $settings + ['header' => TRUE, 'prefix' => '', 'blocklist' => ['rendered']],
      ],
    ]);
    $response = new CacheableResponse();
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags($tags));
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $response);

    (new CdnTagSubscriber(new CacheTags($configFactory)))->onResponse($event);
    return $response;
  }

}
