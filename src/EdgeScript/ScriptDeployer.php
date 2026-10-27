<?php

declare(strict_types=1);

namespace Drupal\bunny_net\EdgeScript;

use Drupal\bunny_net\BunnyClientFactory;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\bunny_net\Paths;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Deploys Edge Scripts, such as pull zone middleware, from files.
 *
 * Scripts are listed in config, with the file holding the code and the
 * variables to set. Secrets come from settings.php only:
 *
 * @code
 * edge_scripts:
 *   middleware:
 *     script_id: 1234
 *     file: ../bunny/middleware.ts
 *     variables:
 *       ORIGIN_HOST: www.example.com
 *
 * $settings['bunny_net']['edge_script_secrets']['middleware']['SIGNING_KEY'] = getenv('BUNNY_SIGNING_KEY');
 * @endcode
 *
 * Files are relative to the Drupal root, like the config sync directory. A
 * script without one is read from Paths::DIRECTORY, as `<name>.ts`.
 */
final class ScriptDeployer {

  /**
   * The script's code matched the file, so nothing was sent.
   */
  public const string UNCHANGED = 'unchanged';

  /**
   * The code was uploaded and published.
   */
  public const string PUBLISHED = 'published';

  /**
   * The code was uploaded, but not published.
   */
  public const string UPLOADED = 'uploaded';

  public function __construct(
    private readonly BunnyClientFactory $clientFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Settings $settings,
    private readonly string $appRoot,
  ) {}

  /**
   * The names of the configured scripts.
   *
   * @return list<string>
   *   Script names.
   */
  public function names(): array {
    return array_map(strval(...), array_keys($this->scripts()));
  }

  /**
   * What is configured for each script, without secret values.
   *
   * @return list<array{name: string, script_id: int, file: string, exists: bool, variables: list<string>, secrets: list<string>}>
   *   One entry per script: the file as configured, whether it exists, and
   *   the names of its variables and of its secrets in settings.php.
   */
  public function overview(): array {
    $secrets = $this->secrets();
    $overview = [];
    foreach ($this->scripts() as $name => $script) {
      $file = $this->path((string) $name, $script);
      $overview[] = [
        'name' => (string) $name,
        'script_id' => (int) ($script['script_id'] ?? 0),
        'file' => $file,
        'exists' => is_file($file),
        'variables' => array_keys(self::stringMap($script['variables'] ?? [])),
        'secrets' => array_keys(self::stringMap($secrets[$name] ?? [])),
      ];
    }
    return $overview;
  }

  /**
   * Deploys one script unless its code already matches the file.
   *
   * @param string $name
   *   The script's name in config.
   * @param bool $force
   *   Deploy even when the code matches, e.g. after changing variables.
   * @param bool $publish
   *   FALSE to upload the code without making it live.
   *
   * @return string
   *   One of the constants.
   *
   * @throws \Drupal\bunny_net\NotConfiguredException
   *   When the script or its file is missing.
   * @throws \Valicm\BunnyToolkit\Http\ApiException
   *   When bunny.net rejects the request.
   */
  public function deploy(string $name, bool $force = FALSE, bool $publish = TRUE): string {
    $script = $this->scripts()[$name] ?? throw new NotConfiguredException(sprintf('No Edge Script named "%s" in bunny_net.settings.', $name));
    $scriptId = (int) ($script['script_id'] ?? 0);
    if ($scriptId <= 0) {
      throw new NotConfiguredException(sprintf('Edge Script "%s" has no script_id.', $name));
    }
    $file = $this->path($name, $script);
    if (!is_file($file)) {
      throw new NotConfiguredException(sprintf('Edge Script "%s": %s does not exist.', $name, $file));
    }
    $code = (string) file_get_contents($file);

    $bunny = $this->clientFactory->get();
    if (!$force) {
      $deployed = $bunny->edgeScripting()->code()->getCode($scriptId);
      if (($deployed['Code'] ?? NULL) === $code) {
        return self::UNCHANGED;
      }
    }

    $bunny->edgeScriptDeployer()->deploy(
      $scriptId,
      $code,
      self::stringMap($script['variables'] ?? []),
      self::stringMap($this->secrets()[$name] ?? []),
      sprintf('Deployed from %s', basename($file)),
      $publish,
    );
    return $publish ? self::PUBLISHED : self::UPLOADED;
  }

  /**
   * The configured scripts.
   *
   * @return array<string, array<string, mixed>>
   *   Scripts keyed by name.
   */
  private function scripts(): array {
    $scripts = $this->configFactory->get('bunny_net.settings')->get('edge_scripts');
    return is_array($scripts) ? array_filter($scripts, is_array(...)) : [];
  }

  /**
   * Secrets from settings.php, keyed by script name.
   *
   * @return array<mixed>
   *   Secrets by script.
   */
  private function secrets(): array {
    $settings = $this->settings->get('bunny_net', []);
    return is_array($settings) && is_array($settings['edge_script_secrets'] ?? NULL) ? $settings['edge_script_secrets'] : [];
  }

  /**
   * A script's file as an absolute path: as configured, or by its name.
   *
   * @param string $name
   *   The script's name in config.
   * @param array<string, mixed> $script
   *   The script's config.
   */
  private function path(string $name, array $script): string {
    $file = is_string($script['file'] ?? NULL) ? trim($script['file']) : '';
    return Paths::resolve($this->appRoot, $file !== '' ? $file : Paths::DIRECTORY . '/' . $name . '.ts');
  }

  /**
   * Name => value pairs as strings.
   *
   * @return array<string, string>
   *   The pairs.
   */
  private static function stringMap(mixed $values): array {
    $map = [];
    foreach (is_array($values) ? $values : [] as $key => $value) {
      if (is_scalar($value)) {
        $map[(string) $key] = (string) $value;
      }
    }
    return $map;
  }

}
