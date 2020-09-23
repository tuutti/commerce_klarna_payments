<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Exception;
use Klarna\ApiException;
use Klarna\Model\UpdateMerchantReferences;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles order capturing when the order is placed.
 */
class OrderTransitionSubscriber implements EventSubscriberInterface {

  /**
   * The klarna connector.
   *
   * @var \Drupal\commerce_klarna_payments\ApiManager
   */
  protected $connector;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  protected $paymentStorage;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_klarna_payments\ApiManager $connector
   *   The Klarna connector.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(ApiManager $connector, LoggerInterface $logger, EntityTypeManagerInterface $entityTypeManager) {
    $this->connector = $connector;
    $this->logger = $logger;
    $this->paymentStorage = $entityTypeManager->getStorage('commerce_payment');
  }

  /**
   * {@inheritdoc}
   */
  protected function getPayment(OrderInterface $order) : ? PaymentInterface {
    $payments = $this->paymentStorage->loadMultipleByOrder($order);
    $plugin = $this->connector->getPlugin($order);

    if (empty($payments)) {
      return NULL;
    }
    $klarna_payment = NULL;

    foreach ($payments as $payment) {
      if ($payment->getPaymentGatewayId() !== $plugin->getEntityId() || $payment->getState()->value !== 'authorization') {
        continue;
      }
      $klarna_payment = $payment;
    }
    return $klarna_payment ?? NULL;
  }

  /**
   * This method is called whenever the order is completed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The transition event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) : void {
    // This should only be triggered when the order is being completed.
    if ($event->getTransition()->getToState()->getId() !== 'completed') {
      return;
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // If the order is already paid in full, there's no need for trying to
    // capture the payment again.
    if ($order->isPaid()) {
      return;
    }

    try {
      $plugin = $this->connector->getPlugin($order);

      $payment = $this->getPayment($order);
      // No payment found in 'authorized' state. This should only happens when
      // we have done a manual capture that didn't capture the full price.
      if (!$payment) {
        // Make a second payment in authorized state with remaining balance.
        $payment = $plugin->createAuthorizationPayment($order);
      }

      $plugin->capturePayment($payment);
    }
    catch (NonKlarnaOrderException $e) {
      return;
    }
    catch (Exception $e) {
      $this->logger->critical(new TranslatableMarkup('Payment capture for order #@order failed: [@exception]: @message', [
        '@order' => $event->getEntity()->id(),
        '@exception' => get_class($e),
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Updates the order number at Klarna on order placement.
   *
   * In difference to the onOrderPlace() callback, which is also triggered on
   * validate and fulfill transitions, this callback is solely for order
   * placement.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The transition event.
   */
  public function updateOrderNumberOnPlace(WorkflowTransitionEvent $event) : void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    try {
      $orderRequest = $this->connector->getOrder($order);

      // Set the order number only if it's not set yet.
      if ($orderRequest->getMerchantReference1()) {
        return;
      }

      $this->connector
        ->getOrderManagementApi($order)
        ->updateMerchantReferences($orderRequest->getOrderId(), NULL, new UpdateMerchantReferences([
          'merchant_reference1' => $order->getOrderNumber(),
        ]));
    }
    catch (ApiException | NonKlarnaOrderException $e) {
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];

    // Subscribe to every known transition phase that leads to 'completed'
    // state.
    foreach (['place', 'validate', 'fulfill'] as $transition) {
      $events[sprintf('commerce_order.%s.post_transition', $transition)] = [['onOrderPlace']];
    }
    $events['commerce_order.place.post_transition'][] = ['updateOrderNumberOnPlace'];
    return $events;
  }

}
