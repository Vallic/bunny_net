<?php

declare(strict_types=1);

namespace Drupal\bunny_net;

/**
 * Where a site keeps what it deploys to bunny.net.
 *
 * One folder beside the Drupal root, in the site's repository but outside
 * the web root:
 *
 * @code
 * bunny/
 *   error-page.html
 *   middleware.ts
 *   rules/
 *     block-probes.yml
 * web/
 * @endcode
 *
 * Config and settings.php can name other places; these are the defaults.
 */
final class Paths {

  /**
   * The folder, relative to the Drupal root.
   */
  public const string DIRECTORY = '../bunny';

  /**
   * An absolute path for a file or directory from config or settings.php.
   *
   * Relative ones are from the Drupal root. "." and ".." are resolved by the
   * text, not the file system, so a file that doesn't exist yet reads as
   * where it would go. Stream wrapper URIs pass as they are.
   */
  public static function resolve(string $appRoot, string $path): string {
    if (str_contains($path, '://')) {
      return $path;
    }
    $parts = [];
    foreach (explode('/', str_starts_with($path, '/') ? $path : $appRoot . '/' . $path) as $part) {
      if ($part === '..') {
        array_pop($parts);
      }
      elseif ($part !== '' && $part !== '.') {
        $parts[] = $part;
      }
    }
    return '/' . implode('/', $parts);
  }

}
