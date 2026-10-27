<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Unit;

use Drupal\bunny_net\PurgeRateLimiter;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Valicm\BunnyToolkit\Purge\PurgeType;

/**
 * Without a Redis server, purges are counted in the process.
 *
 * The shared buckets are the toolkit's RedisTokenBucket, tested there
 * against a real server.
 */
#[Group('bunny_net')]
#[CoversClass(PurgeRateLimiter::class)]
final class PurgeRateLimiterTest extends UnitTestCase {

  /**
   * A site without a Redis connection gets Bunny's limits per process.
   */
  public function testCountsInTheProcessWithoutRedis(): void {
    new Settings([]);
    $limiter = new PurgeRateLimiter(new NullLogger());

    for ($i = 0; $i < PurgeType::Prefix->defaultLimit()->burst; $i++) {
      $this->assertSame(0.0, $limiter->tryConsume(PurgeType::Prefix));
    }
    $this->assertGreaterThan(0.0, $limiter->tryConsume(PurgeType::Prefix));
    $limiter->backOff(PurgeType::Exact, 30.0);
    $this->assertEqualsWithDelta(30.0, $limiter->tryConsume(PurgeType::Exact), 0.5);
  }

  /**
   * A server that does not answer is given up on once, with a warning.
   */
  #[RequiresPhpExtension('redis')]
  public function testServerThatDoesNotAnswer(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    // Never connected, so every command fails.
    $limiter = new PurgeRateLimiter($logger, new \Redis());

    $this->assertSame([0.0, 0.0], [$limiter->tryConsume(PurgeType::Exact), $limiter->tryConsume(PurgeType::Exact)]);
  }

}
