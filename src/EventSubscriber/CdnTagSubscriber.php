<?php

declare(strict_types=1);

namespace Drupal\bunny_net\EventSubscriber;

use Drupal\bunny_net\CacheTags;
use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Valicm\BunnyToolkit\CacheTag\CdnTagHeader;

/**
 * Writes a response's cache tags into the CDN-Tag header bunny.net reads.
 *
 * The header is set before the page cache stores the response, so pages
 * served from Drupal's page cache carry it too.
 */
final class CdnTagSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly CacheTags $cacheTags,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After FinishResponseSubscriber (16), once the cacheability is final.
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Sets the CDN-Tag header.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$event->isMainRequest() || !$response instanceof CacheableResponseInterface || !$this->cacheTags->headerEnabled()) {
      return;
    }
    $header = $this->cacheTags->header($response->getCacheableMetadata()->getCacheTags());
    if (!$header->isEmpty()) {
      $response->headers->set(CdnTagHeader::NAME, $header->value);
    }
  }

}
