<?php

declare(strict_types=1);

namespace Drupal\bunny_net;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Site\Settings;
use GuzzleHttp\Psr7\HttpFactory;
use Valicm\BunnyToolkit\Bunny;

/**
 * Builds the bunny.net API client from the key in settings.php.
 *
 * The key opens the whole bunny.net account, so it is never kept in config,
 * where it would end up in exports and in the database:
 *
 * @code
 * $settings['bunny_net']['api_key'] = getenv('BUNNY_API_KEY');
 * @endcode
 */
final class BunnyClientFactory {

  /**
   * Seconds an API call may take before it fails.
   */
  private const int TIMEOUT = 15;

  /**
   * The client, once built.
   */
  private ?Bunny $bunny = NULL;

  public function __construct(
    private readonly Settings $settings,
    private readonly ClientFactory $httpClientFactory,
  ) {}

  /**
   * Whether settings.php holds an API key.
   */
  public function hasApiKey(): bool {
    return $this->apiKey() !== '';
  }

  /**
   * The bunny.net client, shared for the request.
   *
   * Sharing it means every purge in the request draws on one rate limiter.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When settings.php holds no API key.
   */
  public function get(): Bunny {
    if ($this->bunny === NULL) {
      if (!$this->hasApiKey()) {
        throw new NotConfiguredException('No bunny.net API key: set $settings[\'bunny_net\'][\'api_key\'] in settings.php.');
      }
      $factory = new HttpFactory();
      $this->bunny = new Bunny($this->apiKey(), $this->httpClientFactory->fromOptions(['timeout' => self::TIMEOUT]), $factory, $factory);
    }
    return $this->bunny;
  }

  /**
   * The API key from settings.php, or an empty string.
   */
  private function apiKey(): string {
    $settings = $this->settings->get('bunny_net', []);
    $key = is_array($settings) ? ($settings['api_key'] ?? '') : '';
    return is_string($key) ? trim($key) : '';
  }

}
