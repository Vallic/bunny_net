<?php

declare(strict_types=1);

namespace Drupal\bunny_net\Form;

use Drupal\bunny_net\CachePurger;
use Drupal\bunny_net\NotConfiguredException;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Valicm\BunnyToolkit\Http\ApiException;

/**
 * Empties the whole pull zone after asking.
 */
final class PurgeAllForm extends ConfirmFormBase {

  public function __construct(
    protected CachePurger $purger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('bunny_net.purger'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'bunny_net_purge_all';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Purge everything in pull zone @zone?', ['@zone' => $this->purger->pullZoneId()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Every page and file is fetched from this site again, which can slow it down until the cache fills.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Purge everything');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('bunny_net.settings');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    if (!$this->purger->isEnabled()) {
      $this->messenger()->addWarning($this->t('Purging is off for this site.'));
      $form['actions']['submit']['#disabled'] = TRUE;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->purger->purgeAll();
      $this->messenger()->addStatus($result->isComplete()
        ? $this->t('The pull zone is being purged.')
        : $this->t('The purge rate limit was reached; the purge is queued for cron.'));
    }
    catch (ApiException | ClientExceptionInterface | NotConfiguredException $e) {
      $this->messenger()->addError($this->t('Purging failed: @message', ['@message' => $e->getMessage()]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
