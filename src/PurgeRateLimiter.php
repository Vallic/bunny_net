<?php

declare(strict_types=1);

namespace Drupal\bunny_net;

use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Valicm\BunnyToolkit\Purge\PurgeType;
use Valicm\BunnyToolkit\Purge\RateLimiter\InMemoryTokenBucket;
use Valicm\BunnyToolkit\Purge\RateLimiter\RateLimiter;
use Valicm\BunnyToolkit\Purge\RateLimiter\RedisTokenBucket;

/**
 * Bunny's purge limits, counted once for every PHP process of the site.
 *
 * Bunny counts purges per account. Counted in each process, as the toolkit's
 * in-memory bucket does, every web head and queue runner believes it has the
 * whole limit, and together they collect 429s. Where the site has a Redis or
 * Valkey connection through the Redis module, the buckets live there instead,
 * so two processes cannot both take the last token. Without one, or once the
 * server stops answering, the process counts for itself: purges still go out,
 * and a 429 still backs off.
 *
 * This is the bunny_net.rate_limiter service. A site whose other code purges
 * the same account can count those purges with it, or replace the service
 * with its own RateLimiter.
 */
final class PurgeRateLimiter implements RateLimiter {

  /**
   * Prefix of each bucket's key.
   */
  public const string KEY = 'bunny_net:purge:';

  /**
   * The Redis module's client factory.
   */
  private const string CLIENT_FACTORY = '\Drupal\redis\ClientFactory';

  /**
   * The buckets, once the first purge asks for them.
   */
  private ?RateLimiter $buckets = NULL;

  /**
   * Constructs the limiter.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Told when the server stops answering.
   * @param \Redis|\Predis\ClientInterface|null $redis
   *   A client, for a test. Otherwise the Redis module's, when the site has
   *   a connection configured.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ?object $redis = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tryConsume(PurgeType $type): float {
    return $this->buckets()->tryConsume($type);
  }

  /**
   * {@inheritdoc}
   */
  public function backOff(PurgeType $type, float $seconds): void {
    $this->buckets()->backOff($type, $seconds);
  }

  /**
   * The shared buckets, or the process's own where there is no server.
   */
  private function buckets(): RateLimiter {
    if ($this->buckets === NULL) {
      $client = $this->client();
      $this->buckets = $client !== NULL
        ? new RedisTokenBucket($client, self::KEY, logger: $this->logger)
        : new InMemoryTokenBucket();
    }
    return $this->buckets;
  }

  /**
   * The site's Redis client, or NULL when it has none RedisTokenBucket takes.
   *
   * @return \Redis|\Predis\ClientInterface|null
   *   PhpRedis or Predis; Relay is counted in-process.
   */
  private function client(): ?object {
    $client = $this->redis;
    // Only where a connection is configured: the Redis module otherwise
    // connects to localhost, which may be nothing.
    if ($client === NULL && Settings::get('redis.connection') !== NULL && class_exists(self::CLIENT_FACTORY)) {
      try {
        $client = (self::CLIENT_FACTORY)::getClient();
      }
      catch (\Throwable) {
        return NULL;
      }
    }
    if ($client instanceof \Redis || is_a($client, '\Predis\ClientInterface')) {
      return $client;
    }
    return NULL;
  }

}
