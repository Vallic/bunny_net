<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Unit;

use Drupal\bunny_net\Paths;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Paths from config read as where they are, without "..".
 */
#[Group('bunny_net')]
#[CoversClass(Paths::class)]
final class PathsTest extends UnitTestCase {

  /**
   * Relative, absolute and stream wrapper paths.
   */
  public function testResolve(): void {
    $this->assertSame('/app/bunny/error-page.html', Paths::resolve('/app/web', '../bunny/error-page.html'));
    $this->assertSame('/app/web/rules', Paths::resolve('/app/web', './rules/'));
    $this->assertSame('/srv/cdn/502.html', Paths::resolve('/app/web', '/srv/cdn/../cdn/502.html'));
    $this->assertSame('private://bunny/rules', Paths::resolve('/app/web', 'private://bunny/rules'));
  }

}
