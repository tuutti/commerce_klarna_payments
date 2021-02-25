<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Controller;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_checkout\CheckoutOrderManager;
use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Klarna\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handle Klarna payment redirects.
 */
class RedirectController implements ContainerInjectionInterface {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * The checkout manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManager
   */
  protected $checkoutOrderManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The api manager.
   *
   * @var \Drupal\commerce_klarna_payments\ApiManager
   */
  protected $apiManager;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_checkout\CheckoutOrderManager $checkoutOrderManager
   *   The checkout order manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\commerce_klarna_payments\ApiManager $apiManager
   *   The logger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The api manager.
   */
  public function __construct(
    CheckoutOrderManager $checkoutOrderManager,
    MessengerInterface $messenger,
    ApiManager $apiManager,
    LoggerInterface $logger
  ) {
    $this->checkoutOrderManager = $checkoutOrderManager;
    $this->messenger = $messenger;
    $this->apiManager = $apiManager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_checkout.checkout_order_manager'),
      $container->get('messenger'),
      $container->get('commerce_klarna_payments.api_manager'),
      $container->get('logger.channel.commerce_klarna_payments')
    );
  }

  /**
   * Validates the authorization and handles the redirects.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $commerce_payment_gateway
   *   The payment gateway.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @throws \Drupal\commerce\Response\NeedsRedirectException
   */
  public function handleRedirect(OrderInterface $commerce_order, PaymentGatewayInterface $commerce_payment_gateway, Request $request) {
    $query = $request->request->all();
    $values = NestedArray::getValue($query, ['payment_process', 'offsite_payment']);

    if (empty($values['klarna_authorization_token'])) {
      $message = $this->t('Authorization token not set. This should only happen when Klarna order is incomplete.');
      // Redirect back to review step.
      $this->redirectOnFailure($commerce_order, $message);
    }

    try {
      $response = $this->apiManager
        ->authorizeOrder($commerce_order, $values['klarna_authorization_token']);

      throw new NeedsRedirectException($response->getRedirectUrl());
    }
    catch (\InvalidArgumentException | ApiException $e) {
      $message = $this->t('Authorization validation failed. Please contact store administration if the problem persists.');
      // Redirect back to review step.
      $this->redirectOnFailure($commerce_order, $message, $e);
    }
  }

  /**
   * Redirects on back to previous step on failure.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   The error message.
   * @param \Exception|null $exception
   *   The exception if set.
   */
  private function redirectOnFailure(
    OrderInterface $order,
    TranslatableMarkup $message,
    \Exception $exception = NULL
  ) : void {

    $loggerMessage = (string) $message;

    // Override error message from exception if set.
    if ($exception) {
      $loggerMessage = sprintf('[%s]: %s. ', get_class($exception), $exception->getMessage());
    }
    $loggerMessage .= sprintf('Order: %s', $order->id());

    $this->logger->critical($loggerMessage);
    $this->messenger->addError($message);

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    $checkout_flow_plugin = $checkout_flow->getPlugin();

    $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);

    $checkout_flow_plugin->redirectToStep($checkout_flow_plugin->getPreviousStepId($step_id));
  }

}
