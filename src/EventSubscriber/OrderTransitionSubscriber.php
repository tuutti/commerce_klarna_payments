<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\commerce_klarna_payments\ApiManagerInterface;
use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\PaymentGatewayPluginTrait;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Klarna\ApiException;
use Klarna\OrderManagement\Model\Order;
use Klarna\OrderManagement\Model\UpdateMerchantReferences;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles order capturing when the order is placed.
 */
final class OrderTransitionSubscriber implements EventSubscriberInterface {

  use PaymentGatewayPluginTrait;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_klarna_payments\ApiManagerInterface $apiManager
   *   The Klarna connector.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private ApiManagerInterface $apiManager,
    private LoggerInterface $logger
  ) {
  }

  /**
   * This method is called after the order is completed.
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
      $plugin = $this->getPlugin($order);
    }
    catch (NonKlarnaOrderException) {
      return;
    }
    $orderResponse = $this->apiManager->getOrder($order);
    $payment = $plugin->getOrCreatePayment($order);

    // Mark local payment as captured if the Klarna order is captured before
    // ::capturePayment() is called.
    if ($orderResponse->getStatus() === Order::STATUS_CAPTURED) {
      $payment->getState()
        ->applyTransitionById('capture');

      $captures = $orderResponse->getCaptures();

      if ($capture = reset($captures)) {
        $payment->setRemoteId($capture->getCaptureId());
      }
      $payment->setAmount(
        UnitConverter::toPrice($orderResponse->getCapturedAmount(), $orderResponse->getPurchaseCurrency())
      )
        ->save();

      return;
    }
    $plugin->capturePayment($payment, $order->getBalance());
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
      $orderRequest = $this->apiManager->getOrder($order);

      // Set the order number only if it's not set yet.
      if ($orderRequest->getMerchantReference1()) {
        return;
      }

      $this->apiManager
        ->getOrderManagementApi($order)
        ->updateMerchantReferences($orderRequest->getOrderId(), NULL, new UpdateMerchantReferences([
          'merchant_reference1' => $order->getOrderNumber(),
        ]));
    }
    catch (ApiException | NonKlarnaOrderException $e) {
      $this->logger->error(
        new FormattableMarkup('Failed to update merchant references (#@order)  @message', [
          '@message' => $e->getMessage(),
          '@order' => $order->id(),
        ])
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    $events = [];

    // Subscribe to every known transition phase that leads to 'completed'
    // state.
    foreach (['place', 'validate', 'fulfill'] as $transition) {
      $events[sprintf('commerce_order.%s.pre_transition', $transition)] = [['onOrderPlace']];
    }
    $events['commerce_order.place.post_transition'][] = ['updateOrderNumberOnPlace'];
    return $events;
  }

}
