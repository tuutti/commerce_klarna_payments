<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Exception;
use Klarna\ObjectSerializer;

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
    $plugin = $this->entity
      ->getPaymentGateway()
      ->getPlugin();

    if (!$order = $this->entity->getOrder()) {
      $this->messenger()->addError(
        $this->t('The provided payment has no order referenced. Please contact store administration if the problem persists.')
      );

      return $form;
    }

    $form = $this
      ->buildRedirectForm(
        $form,
        $form_state,
        $plugin->getReturnUri($order, 'commerce_klarna_payments.redirect')
      );

    try {
      $data = $plugin->getApiManager()
        ->buildTransaction($order);

      $form['payment_methods'] = [
        '#theme' => 'commerce_klarna_payments_container',
        // @todo Check if we can cache this.
        '#cache' => ['max-age' => 0],
        '#attached' => [
          'library' => ['commerce_klarna_payments/klarna-js-sdk'],
          'drupalSettings' => [
            'klarnaPayments' => (array) ObjectSerializer::sanitizeForSerialization($data),
          ],
        ],
      ];

      $form['klarna_authorization_token'] = [
        '#type' => 'hidden',
        '#value' => '',
        '#attributes' => ['data-klarna-selector' => 'authorization-token'],
      ];

      if (empty($data['payment_method_categories'])) {
        $this->messenger()->addError(
          $this->t('No payment method categories found. Usually this means that Klarna is not supported in the given country. Please contact store administration if you think this is an error.')
        );
        // Trigger a form error so we can disable continue button.
        $form_state->setErrorByName('klarna_authorization_token');
      }
    }
    catch (Exception $e) {
      $this->messenger()->addError(
        $this->t('An unknown error occurred. Please contact store administration if the problem persists.')
      );

      $plugin->getLogger()
        ->error(
          $this->t('An error occurred for order #@id: [@exception]: @message', [
            '@id' => $order->id(),
            '@exception' => get_class($e),
            '@message' => $e->getMessage(),
          ])
        );
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, $redirect_url, array $data = [], $redirect_method = self::REDIRECT_GET) {
    $form['#process'][] = [get_class($this), 'processRedirectForm'];
    $form['#redirect_url'] = $redirect_url;
    $form['#method'] = self::REDIRECT_GET;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function processRedirectForm(array $element, FormStateInterface $form_state, array &$complete_form) {
    $element = parent::processRedirectForm($element, $form_state, $complete_form);

    foreach (Element::children($complete_form['actions']) as $name) {
      if ($complete_form['actions'][$name]['#type'] !== 'submit') {
        continue;
      }
      if ($form_state->getErrors()) {
        // Disable continue button if form has errors.
        $complete_form['actions'][$name]['#attributes']['disabled'] = 'disabled';
      }
      // Append appropriate selector so we can disable the submit button until
      // we have authorization token.
      $complete_form['actions'][$name]['#attributes']['data-klarna-selector'] = 'submit';
      $complete_form['actions'][$name]['#value'] = new TranslatableMarkup('Continue');
    }
    return $element;
  }

}
