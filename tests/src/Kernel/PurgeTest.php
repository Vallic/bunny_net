<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\bunny_net\CachePurger;
use Drupal\bunny_net\Hook\CacheRebuildHooks;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\bunny_net\Plugin\QueueWorker\PurgeQueueWorker;
use Drupal\bunny_net\PurgeCollector;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Queue\QueueInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;
use Valicm\BunnyToolkit\Purge\PurgeType;
use Valicm\BunnyToolkit\Purge\RateLimiter\RateLimiter;

/**
 * Invalidated cache tags reach bunny.net once, or wait in the queue.
 */
#[Group('bunny_net')]
#[CoversClass(CachePurger::class)]
#[CoversClass(PurgeCollector::class)]
#[CoversClass(PurgeQueueWorker::class)]
#[CoversClass(CacheRebuildHooks::class)]
#[RunTestsInSeparateProcesses]
final class PurgeTest extends BunnyNetKernelTestBase {

  /**
   * Each tag goes once, at the end of the request, in the header's format.
   */
  public function testInvalidatedTagsArePurgedOnceAtTheEnd(): void {
    Cache::invalidateTags(['node:1', 'node_list']);
    Cache::invalidateTags(['node:1', 'rendered']);
    $this->assertCount(0, $this->sent, 'Nothing is sent before the request ends.');

    $this->answerNoContent(2);
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();

    $format = new HashedTagList();
    $this->assertSame([
      ['POST /pullzone/4321/purgeCache', ['CacheTag' => $format->purgeValue('node:1')]],
      ['POST /pullzone/4321/purgeCache', ['CacheTag' => $format->purgeValue('node_list')]],
    ], $this->sentRequests(), 'rendered is on the blocklist.');
  }

  /**
   * Blocklist wildcards keep tags out of the purge, as out of the header.
   */
  public function testBlocklistedTagsAreNotPurged(): void {
    $this->config('cache_purifier.settings')->set('outbound.blocklist', ['config:*', '*_list'])->save();
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();
    $this->answerNoContent();

    $result = $this->container->get('bunny_net.purger')->purgeTags(['node_list', 'node:1', 'auction_list']);

    $this->assertSame(['node:1'], $result->purged);
    $this->assertSame([
      ['POST /pullzone/4321/purgeCache', ['CacheTag' => (new HashedTagList())->purgeValue('node:1')]],
    ], $this->sentRequests());
  }

  /**
   * The prefix goes into the purge, as it goes into the header.
   */
  public function testPrefixedTags(): void {
    $this->config('bunny_net.settings')->set('tags.prefix', 'rum:')->save();
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();
    $this->answerNoContent();

    $this->container->get('bunny_net.purger')->purgeTags(['node:1']);

    $this->assertSame(['CacheTag' => (new HashedTagList())->purgeValue('rum:node:1')], $this->sentRequests()[0][1]);
  }

  /**
   * A copy of production with purging off sends nothing.
   */
  public function testDisabledSitesSendNothing(): void {
    $this->config('bunny_net.settings')->set('purge.enabled', FALSE)->save();

    Cache::invalidateTags(['node:1']);
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();

    $this->assertCount(0, $this->sent);
    $this->assertSame(0, $this->queue()->numberOfItems());
    $this->expectException(NotConfiguredException::class);
    $this->container->get('bunny_net.purger')->purgeAll();
  }

  /**
   * In queue mode, cron sends the purge.
   */
  public function testQueueMode(): void {
    $this->config('bunny_net.settings')->set('purge.mode', 'queue')->save();
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();

    Cache::invalidateTags(['node:1']);
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();
    $this->assertCount(0, $this->sent);
    $this->assertSame(1, $this->queue()->numberOfItems());

    $this->answerNoContent();
    $this->container->get('cron')->run();

    $this->assertCount(1, $this->sent);
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * What bunny.net can't take now is queued and sent by cron.
   */
  public function testDeferredPurgesAreQueued(): void {
    $this->answers->append(new Response(204), new Response(503), new Response(204));
    $result = $this->container->get('bunny_net.purger')->purgeTags(['node:1', 'node:2', 'node:3']);

    $this->assertCount(3, $this->sent, 'All three are sent at once.');
    $this->assertSame(['node:1', 'node:3'], $result->purged);
    $item = $this->queue()->claimItem();
    $this->assertInstanceOf(\stdClass::class, $item);
    $this->assertSame(['type' => 'tags', 'items' => ['node:2']], $item->data);
    $this->queue()->releaseItem($item);

    $this->answerNoContent();
    $this->container->get('cron')->run();

    $this->assertSame(['CacheTag' => (new HashedTagList())->purgeValue('node:2')], $this->sentRequests()[3][1]);
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * Purges draw on bunny_net.rate_limiter, which a site can replace.
   */
  public function testPurgesDrawOnTheRateLimiterService(): void {
    $this->container->set('bunny_net.rate_limiter', new class implements RateLimiter {

      /**
       * {@inheritdoc}
       */
      public function tryConsume(PurgeType $type): float {
        return 30.0;
      }

      /**
       * {@inheritdoc}
       */
      public function backOff(PurgeType $type, float $seconds): void {}

    });

    $result = $this->container->get('bunny_net.purger')->purgeTags(['node:1']);

    $this->assertCount(0, $this->sent, 'The limiter had no tokens.');
    $this->assertSame(['node:1'], $result->deferred);
    $this->assertSame(1, $this->queue()->numberOfItems());
  }

  /**
   * One at a time, a failure stops the rest from being sent.
   */
  public function testSequentialPurges(): void {
    $this->config('bunny_net.settings')->set('purge.concurrency', 1)->save();
    $this->answers->append(new Response(204), new Response(503));
    $this->container->get('bunny_net.purger')->purgeTags(['node:1', 'node:2', 'node:3']);

    $this->assertCount(2, $this->sent);
    $item = $this->queue()->claimItem();
    $this->assertInstanceOf(\stdClass::class, $item);
    $this->assertSame(['type' => 'tags', 'items' => ['node:2', 'node:3']], $item->data);
  }

  /**
   * A full purge sends no body, which bunny.net takes as everything.
   */
  public function testPurgeAll(): void {
    $this->answerNoContent();

    $this->container->get('bunny_net.purger')->purgeAll();

    $this->assertSame([['POST /pullzone/4321/purgeCache', NULL]], $this->sentRequests());
  }

  /**
   * A cache rebuild empties the pull zone only when asked to.
   */
  public function testPurgeOnCacheRebuild(): void {
    $this->container->get('module_handler')->invoke('bunny_net', 'rebuild');
    $this->assertCount(0, $this->sent);

    $this->config('bunny_net.settings')->set('purge.on_cache_rebuild', TRUE)->save();
    $this->answerNoContent();
    $this->container->get('module_handler')->invoke('bunny_net', 'rebuild');

    $this->assertSame([['POST /pullzone/4321/purgeCache', NULL]], $this->sentRequests());
  }

  /**
   * A rejected key is logged, not retried forever.
   */
  public function testRejectedPurgesAreNotQueued(): void {
    $this->answers->append(new Response(401, ['Content-Type' => 'application/json'], '{"Message":"Unauthorized"}'));

    Cache::invalidateTags(['node:1']);
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();

    $this->assertCount(1, $this->sent);
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * The purge queue.
   */
  private function queue(): QueueInterface {
    return $this->container->get('queue')->get(CachePurger::QUEUE);
  }

}
