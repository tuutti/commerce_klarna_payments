<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use Klarna\Rest\Transport\Exception\ConnectorException;

/**
 * Provides the Klarna payments form.
 */
final class KlarnaOffsiteForm extends PaymentOffsiteForm {

  use MessengerTrait;
  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The payment.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna $plugin */
    $plugin = $this->entity->getPaymentGateway()->getPlugin();

    if (!$order = $this->entity->getOrder()) {
      $this->messenger()->addError($this->t('The provided payment has no order referenced. Please contact store administration if the problem persists.'));

      return $form;
    }

    try {
      $session = $plugin->getKlarnaConnector()->buildTransaction($order);

      $form['#attached']['library'][] = 'commerce_klarna_payments/klarna-js-sdk';
      $form['#attached']['drupalSettings']['klarnaPayments'] = $session->toArray();

      $form['klarna-payments-container'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'klarna-payments-container',
        ],
      ];

      return $form;
    }
    catch (ConnectorException $e) {
      // Session initialization failed.
      $this->messenger()->addError($this->t('Failed to populate Klarna order. Please contact store administration if the problem persists.'));

      return $form;
    }
    catch (Exception $e) {
      $this->messenger()->addError($this->t('An unknown error occurred. Please contact store administration if the problem persists.'));

      return $form;
    }
  }

}
