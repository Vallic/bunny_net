<?php

declare(strict_types=1);

namespace Drupal\bunny_net;

use Drupal\Core\Config\ConfigFactoryInterface;
use Valicm\BunnyToolkit\CacheTag\CdnTagHeader;
use Valicm\BunnyToolkit\CacheTag\HashedTagList;
use Valicm\BunnyToolkit\CacheTag\TagFormat;

/**
 * Which cache tags reach bunny.net, and how they are written.
 *
 * The response header and the purge go through the same filter and format,
 * so a purged tag always matches the header it was written into.
 *
 * - Tags on the blocklist are neither written nor purged. `rendered` is
 *   invalidated by every theme and config change, so purging it would empty
 *   the whole pull zone each time.
 * - The prefix keeps sites that share a pull zone from purging each other's
 *   pages.
 */
final class CacheTags {

  /**
   * The tag format, shared with the purger.
   */
  private readonly TagFormat $format;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
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
   * The CDN-Tag header for a response carrying these tags.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   */
  public function header(iterable $tags): CdnTagHeader {
    return $this->format->header($this->filter($tags));
  }

  /**
   * The tags as bunny.net sees them: blocklisted ones gone, the rest prefixed.
   *
   * @param iterable<string> $tags
   *   Drupal's cache tags.
   *
   * @return list<string>
   *   The tags to write or purge, without duplicates.
   */
  public function filter(iterable $tags): array {
    $config = $this->configFactory->get('bunny_net.settings');
    $prefix = (string) $config->get('tags.prefix');
    $blocklist = $this->blocklistPattern((array) $config->get('tags.blocklist'));

    $filtered = [];
    foreach ($tags as $tag) {
      if ($blocklist === NULL || preg_match($blocklist, $tag) !== 1) {
        $filtered[$prefix . $tag] = TRUE;
      }
    }
    return array_map(strval(...), array_keys($filtered));
  }

  /**
   * One regular expression for the blocklist, where * matches anything.
   *
   * @param array<mixed> $entries
   *   The blocklist from config.
   */
  private function blocklistPattern(array $entries): ?string {
    $patterns = [];
    foreach ($entries as $entry) {
      if (is_string($entry) && trim($entry) !== '') {
        $patterns[] = str_replace('\*', '.*', preg_quote(trim($entry), '/'));
      }
    }
    return $patterns === [] ? NULL : '/^(?:' . implode('|', $patterns) . ')$/';
  }

}
