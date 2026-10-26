<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Plugin\QueueWorker;

use Drupal\bunny_net\CachePurger;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends purges that bunny.net's rate limits deferred, or that were queued.
 */
#[QueueWorker(
  id: CachePurger::QUEUE,
  title: new TranslatableMarkup('bunny.net purges'),
  cron: ['time' => 30],
)]
final class PurgeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Seconds one item may wait for the rate limits.
   */
  private const float MAX_WAIT = 5.0;

  /**
   * The purger.
   */
  protected CachePurger $purger;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->purger = $container->get('bunny_net.purger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !in_array($data['type'] ?? NULL, ['tags', 'urls', 'all'], TRUE) || !is_array($data['items'] ?? NULL)) {
      return;
    }
    $result = $this->purger->processQueued([
      'type' => $data['type'],
      'items' => array_values(array_map(strval(...), $data['items'])),
    ], self::MAX_WAIT);

    if (!$result->isComplete()) {
      // The rest is queued again; stop until the next cron run.
      throw new SuspendQueueException((string) new TranslatableMarkup('bunny.net purge rate limit reached: @reason', ['@reason' => $result->reason ?? '']));
    }
  }

}
