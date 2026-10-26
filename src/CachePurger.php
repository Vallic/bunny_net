<?php

declare(strict_types=1);

namespace Drupal\bunny_net;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;
use Valicm\BunnyToolkit\Purge\PurgeResult;

/**
 * Purges the site's pull zone, queueing what bunny.net's rate limits defer.
 *
 * Nothing is purged unless purging is enabled and a pull zone is set, so a
 * local or staging copy of the production config cannot empty production's
 * cache. Turn it on per environment in settings.php:
 *
 * @code
 * $config['bunny_net.settings']['purge']['enabled'] = TRUE;
 * @endcode
 */
final class CachePurger {

  /**
   * The queue that holds purges to retry.
   */
  public const string QUEUE = 'bunny_net_purge';

  public function __construct(
    private readonly BunnyClientFactory $clientFactory,
    private readonly CacheTags $cacheTags,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether this site purges: enabled, with a pull zone and an API key.
   */
  public function isEnabled(): bool {
    $config = $this->configFactory->get('bunny_net.settings');
    return $config->get('purge.enabled') && $this->pullZoneId() > 0 && $this->clientFactory->hasApiKey();
  }

  /**
   * The configured pull zone, or 0.
   */
  public function pullZoneId(): int {
    return (int) $this->configFactory->get('bunny_net.settings')->get('pull_zone_id');
  }

  /**
   * Purges the pages carrying these tags, as cache tag invalidation does.
   *
   * Depending on the purge mode the tags are sent now, with what the rate
   * limits defer queued, or all queued for cron.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   */
  public function invalidateTags(iterable $tags): void {
    if (!$this->isEnabled()) {
      return;
    }
    $tags = $this->cacheTags->filter($tags);
    if ($tags === []) {
      return;
    }
    if ($this->configFactory->get('bunny_net.settings')->get('purge.mode') === 'queue') {
      $this->queue('tags', $tags);
      return;
    }
    $this->purge('tags', $tags, 0.0);
  }

  /**
   * Purges the pages carrying these tags now; what can't go is queued.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   * @param float $maxWait
   *   Seconds to wait for rate limits before queueing the rest.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When purging is disabled.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request, e.g. for a wrong API key.
   */
  public function purgeTags(iterable $tags, float $maxWait = 0.0): PurgeResult {
    $this->ensureEnabled();
    return $this->purge('tags', $this->cacheTags->filter($tags), $maxWait);
  }

  /**
   * Purges URLs now; what can't go is queued.
   *
   * @param list<string> $urls
   *   Absolute URLs. A * makes one a prefix purge.
   * @param float $maxWait
   *   Seconds to wait for rate limits before queueing the rest.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When purging is disabled.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function purgeUrls(array $urls, float $maxWait = 0.0): PurgeResult {
    $this->ensureEnabled();
    return $this->purge('urls', $urls, $maxWait);
  }

  /**
   * Empties the whole pull zone; queued if the rate limit is reached.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When purging is disabled.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function purgeAll(float $maxWait = 0.0): PurgeResult {
    $this->ensureEnabled();
    $this->logger->notice('Purging everything in pull zone @zone.', ['@zone' => $this->pullZoneId()]);
    return $this->purge('all', [(string) $this->pullZoneId()], $maxWait);
  }

  /**
   * Sends a queued purge; what is deferred again goes back on the queue.
   *
   * @param array{type: 'tags'|'urls'|'all', items: list<string>} $item
   *   The queue item.
   * @param float $maxWait
   *   Seconds to wait for rate limits.
   */
  public function processQueued(array $item, float $maxWait): PurgeResult {
    if (!$this->isEnabled()) {
      // Disabled since it was queued: nothing to do.
      return new PurgeResult([]);
    }
    return $this->purge($item['type'], $item['items'], $maxWait);
  }

  /**
   * Sends one kind of purge and queues what was deferred.
   *
   * @param 'tags'|'urls'|'all' $type
   *   What the items are. Tags are already filtered.
   * @param list<string> $items
   *   The tags, URLs, or the pull zone ID.
   * @param float $maxWait
   *   Seconds to wait for rate limits before queueing the rest.
   */
  private function purge(string $type, array $items, float $maxWait): PurgeResult {
    if ($items === []) {
      return new PurgeResult([]);
    }
    $purger = $this->clientFactory->get()->purger($this->cacheTags->format(), maxWait: $maxWait, logger: $this->logger);
    $result = match ($type) {
      'tags' => $purger->purgeTags($this->pullZoneId(), $items),
      'urls' => $purger->purgeUrls($items),
      'all' => $purger->purgePullZone($this->pullZoneId()),
    };
    if (!$result->isComplete()) {
      $this->queue($type, $result->deferred);
    }
    return $result;
  }

  /**
   * Adds a purge to the queue.
   *
   * @param 'tags'|'urls'|'all' $type
   *   What the items are.
   * @param list<string> $items
   *   The items.
   */
  private function queue(string $type, array $items): void {
    $this->queueFactory->get(self::QUEUE)->createItem(['type' => $type, 'items' => $items]);
  }

  /**
   * Throws unless this site purges, with what is missing.
   */
  public function ensureEnabled(): void {
    if (!$this->isEnabled()) {
      throw new NotConfiguredException('Purging is off for this site. It needs purge.enabled, a pull zone ID in bunny_net.settings and an API key in settings.php.');
    }
  }

}
