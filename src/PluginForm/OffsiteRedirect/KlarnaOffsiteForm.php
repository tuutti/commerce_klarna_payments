<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\PluginForm\OffsiteRedirect;

use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
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
   * Gets the payment plugin.
   *
   * @return \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   *   The payment plugin.
   */
  private function getPaymentPlugin() : Klarna {
    return $this->entity->getPaymentGateway()->getPlugin();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $plugin = $this->getPaymentPlugin();

    $form = parent::buildConfigurationForm($form, $form_state);

    if (!$order = $this->entity->getOrder()) {
      $this->messenger()->addError(
        $this->t('The provided payment has no order referenced. Please contact store administration if the problem persists.')
      );

      return $form;
    }
    $form = $this->buildRedirectForm($form, $form_state, $plugin->getReturnUri($order, 'commerce_klarna_payments.redirect'));

    try {
      $form['#attached']['library'][] = 'commerce_klarna_payments/klarna-js-sdk';

      $form['#attached']['drupalSettings']['klarnaPayments'] = $plugin->getKlarnaConnector()->buildTransaction($order, $plugin);

      $form['klarna-payments-container'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'klarna-payments-container',
        ],
      ];

      $form['klarna_authorization_token'] = [
        '#type' => 'hidden',
        '#value' => '',
        '#attributes' => ['klarna-selector' => 'authorization-token'],
      ];

      return $form;
    }
    catch (ConnectorException $e) {
      // Session initialization failed.
      $this->messenger()->addError(
        $this->t('Failed to populate Klarna order. Please contact store administration if the problem persists.')
      );
      $plugin->getLogger()->error(
        $this->t('Failed to populate Klara order #@id: @message', [
          '@id' => $order->id(),
          '@message' => $e->getMessage(),
        ])
      );

      return $form;
    }
    catch (Exception $e) {
      $this->messenger()->addError(
        $this->t('An unknown error occurred. Please contact store administration if the problem persists.')
      );

      $plugin->getLogger()->error(
        $this->t('An error occurred for order #@id: @message', [
          '@id' => $order->id(),
          '@message' => $e->getMessage(),
        ])
      );

      return $form;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, $redirect_url, array $data = [], $redirect_method = self::REDIRECT_GET) {

    $form['commerce_message'] = [
      '#weight' => -10,
      '#process' => [
        [get_class($this), 'processRedirectForm'],
      ],
      '#action' => $redirect_url,
    ];

    return $form;
  }

}
