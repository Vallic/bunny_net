<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Hook;

use Drupal\bunny_net\BunnyClientFactory;
use Drupal\bunny_net\CachePurger;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Valicm\BunnyToolkit\Http\ApiException;

/**
 * The status report entry: API key, pull zone, and whether purging is on.
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'bunny_net.client_factory')]
    private readonly BunnyClientFactory $clientFactory,
    #[Autowire(service: 'bunny_net.purger')]
    private readonly CachePurger $purger,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirement = ['title' => $this->t('bunny.net')];

    if (!$this->clientFactory->hasApiKey()) {
      $requirement['value'] = $this->t('No API key');
      $requirement['description'] = $this->t("Set <code>\$settings['bunny_net']['api_key']</code> in settings.php.");
      $requirement['severity'] = RequirementSeverity::Warning;
      return ['bunny_net' => $requirement];
    }
    $pullZoneId = $this->purger->pullZoneId();
    if ($pullZoneId === 0) {
      $requirement['value'] = $this->t('No pull zone');
      $requirement['description'] = $this->t('Set the pull zone ID on the <a href=":url">bunny.net settings</a>.', [
        ':url' => Url::fromRoute('bunny_net.settings')->toString(),
      ]);
      $requirement['severity'] = RequirementSeverity::Warning;
      return ['bunny_net' => $requirement];
    }

    try {
      $zone = $this->clientFactory->get()->core()->pullZone()->getPullZone($pullZoneId);
    }
    catch (ApiException | ClientExceptionInterface $e) {
      $requirement['value'] = $this->t('Pull zone @id unreachable', ['@id' => $pullZoneId]);
      $requirement['description'] = $e->getMessage();
      $requirement['severity'] = RequirementSeverity::Error;
      return ['bunny_net' => $requirement];
    }

    $requirement['value'] = $this->t('Pull zone %name (@id)', ['%name' => $zone['Name'] ?? '', '@id' => $pullZoneId]);
    if ($this->purger->isEnabled()) {
      $requirement['description'] = $this->t('Cache tags are purged when Drupal invalidates them.');
      $requirement['severity'] = RequirementSeverity::OK;
    }
    else {
      $requirement['description'] = $this->t('Purging is off for this site, so invalidated pages stay cached until their cache lifetime ends.');
      $requirement['severity'] = RequirementSeverity::Info;
    }
    return ['bunny_net' => $requirement];
  }

}
