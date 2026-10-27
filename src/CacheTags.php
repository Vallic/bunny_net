<?php

declare(strict_types=1);

namespace Drupal\bunny_net;

use Drupal\cache_purifier\OutboundTags;
use Drupal\Core\Config\ConfigFactoryInterface;
use Valicm\BunnyToolkit\CacheTag\CdnTagHeader;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;
use Valicm\BunnyToolkit\CacheTag\TagFormat;

/**
 * Which cache tags reach bunny.net, and how they are written.
 *
 * Cache Purifier's outbound policy decides the tags: its blocklist, aliases,
 * buckets and order. The response header and the purge both go through it
 * and through the same format, so a purged tag always matches the header it
 * was written into. The prefix keeps sites that share a pull zone from
 * purging each other's pages.
 *
 * Until Cache Purifier is installed, as on a site updating from a version
 * without it, nothing is written or purged; bunny_net_update_11001()
 * installs it.
 */
final class CacheTags {

  /**
   * The tag format, shared with the purger.
   */
  private readonly TagFormat $format;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?OutboundTags $outbound = NULL,
  ) {
    $this->format = new HashedTagList();
  }

  /**
   * The format the header is written in and tags are purged with.
   */
  public function format(): TagFormat {
    return $this->format;
  }

  /**
   * Whether responses get a CDN-Tag header.
   */
  public function headerEnabled(): bool {
    return (bool) $this->configFactory->get('bunny_net.settings')->get('tags.header');
  }

  /**
   * The CDN-Tag header for a response bunny.net may cache.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   */
  public function header(iterable $tags): CdnTagHeader {
    return $this->format->header($this->outbound === NULL ? [] : $this->prefix($this->outbound->forHeader($tags)));
  }

  /**
   * The tags to purge for invalidated cache tags, prefixed.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   *
   * @return list<string>
   *   The tags to purge, without duplicates.
   */
  public function filter(iterable $tags): array {
    return $this->outbound === NULL ? [] : $this->prefix($this->outbound->forPurge($tags));
  }

  /**
   * Prepends the configured prefix.
   *
   * @param list<string> $tags
   *   The tags.
   *
   * @return list<string>
   *   The prefixed tags.
   */
  private function prefix(array $tags): array {
    $prefix = (string) $this->configFactory->get('bunny_net.settings')->get('tags.prefix');
    return $prefix === '' ? $tags : array_map(static fn(string $tag): string => $prefix . $tag, $tags);
  }

}
