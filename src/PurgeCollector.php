<?php

declare(strict_types=1);

namespace Drupal\bunny_net;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use Psr\Log\LoggerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Valicm\BunnyToolkit\Http\ApiException;

/**
 * Collects the cache tags a request invalidates and purges them at its end.
 *
 * Saving one entity invalidates the same tags several times, so they are
 * gathered and sent once, after the response has gone to the browser.
 */
final class PurgeCollector implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * Tags invalidated in this request, as keys.
   *
   * @var array<string, true>
   */
  private array $tags = [];

  /**
   * Constructs the collector.
   *
   * @param \Closure(): \Drupal\bunny_net\CachePurger $purger
   *   Returns the purger.
   * @param \Closure(): \Psr\Log\LoggerInterface $logger
   *   Returns the logger.
   */
  public function __construct(
    private readonly \Closure $purger,
    private readonly \Closure $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    foreach ($tags as $tag) {
      $this->tags[$tag] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    if ($this->tags === []) {
      return;
    }
    $tags = array_map(strval(...), array_keys($this->tags));
    $this->tags = [];

    try {
      ($this->purger)()->invalidateTags($tags);
    }
    catch (ApiException | ClientExceptionInterface | NotConfiguredException $e) {
      // Retrying won't help a wrong key or pull zone, so these are logged.
      $logger = ($this->logger)();
      assert($logger instanceof LoggerInterface);
      $logger->error('Purging @count cache tag(s) failed: @message', [
        '@count' => count($tags),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
