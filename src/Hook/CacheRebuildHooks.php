<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Hook;

use Drupal\bunny_net\CachePurger;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Valicm\BunnyToolkit\Http\ApiException;

/**
 * Empties the pull zone on a cache rebuild, when purge.on_cache_rebuild is set.
 *
 * For example on `drush cr` at the end of a deploy.
 */
final class CacheRebuildHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'bunny_net.purger')]
    private readonly CachePurger $purger,
    #[Autowire(service: 'logger.channel.bunny_net')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_rebuild().
   */
  #[Hook('rebuild')]
  public function rebuild(): void {
    if (!$this->configFactory->get('bunny_net.settings')->get('purge.on_cache_rebuild') || !$this->purger->isEnabled()) {
      return;
    }
    try {
      $this->purger->purgeAll();
    }
    catch (ApiException | ClientExceptionInterface | NotConfiguredException $e) {
      $this->logger->error('Purging everything on cache rebuild failed: @message', ['@message' => $e->getMessage()]);
    }
  }

}
