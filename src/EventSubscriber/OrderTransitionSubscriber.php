<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Klarna\ApiException;
use Klarna\OrderManagement\Model\Order;
use Klarna\OrderManagement\Model\UpdateMerchantReferences;
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
  protected $apiManager;

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
   * @param \Drupal\commerce_klarna_payments\ApiManager $apiManager
   *   The Klarna connector.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(ApiManager $apiManager, LoggerInterface $logger, EntityTypeManagerInterface $entityTypeManager) {
    $this->apiManager = $apiManager;
    $this->logger = $logger;
    $this->paymentStorage = $entityTypeManager->getStorage('commerce_payment');
  }

  /**
   * {@inheritdoc}
   */
  protected function getPayment(OrderInterface $order) : ? PaymentInterface {
    $payments = $this->paymentStorage->loadMultipleByOrder($order);
    $plugin = $this->apiManager->getPlugin($order);

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
   * Syncs payments from Klarna merchant panel.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Klarna\OrderManagement\Model\Order $orderResponse
   *   The order response.
   * @param \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna $plugin
   *   The payment plugin.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function syncPayments(OrderInterface $order, Order $orderResponse, Klarna $plugin) : void {
    $payment = $this->getPayment($order);

    foreach ($orderResponse->getCaptures() as $capture) {
      // Skip already synced orders.
      if ($this->paymentStorage->loadByRemoteId($capture->getCaptureId())) {
        continue;
      }
      $paymentAmount = UnitConverter::toPrice($capture->getCapturedAmount(), $orderResponse->getPurchaseCurrency());

      // Attempt to use payment created by Klarna::onReturn().
      if (!$payment || $payment->isCompleted()) {
        $payment = $plugin->createPayment($order);
      }
      $payment->getState()
        ->applyTransitionById('capture');
      $payment
        ->setAmount($paymentAmount)
        ->setRemoteId($capture->getCaptureId())
        ->save();
    }
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

    // Track remaining balance to figure out if we need to make
    // any further captures.
    $remainingBalance = $order->getTotalPrice();

    try {
      $plugin = $this->apiManager->getPlugin($order);
    }
    catch (NonKlarnaOrderException $e) {
      return;
    }

    $orderResponse = $this->apiManager->getOrder($order);

    // Sync all payments made via merchant panel.
    $this->syncPayments($order, $orderResponse, $plugin);

    // We shouldn't make any captures if the order differs from the one on
    // merchant panel and instead instruct the user to do any further
    // actions via merchant panel.
    if (!$this->apiManager->orderIsInSync($order, $orderResponse)) {

      // Out of sync order must be captured before we can place the order.
      if ($orderResponse->getStatus() !== 'CAPTURED') {
        throw new PaymentGatewayException(
          sprintf('Order (%s) is out of sync, but not fully captured yet.', $order->id())
        );
      }

      // Mark order as paid in full if Klarna says the order is captured.
      $payment = $this->getPayment($order) ?? $plugin->createPayment($order);
      $payment->getState()
        ->applyTransitionById('capture');
      $payment
        ->setAmount($order->getTotalPrice())
        ->save();

      return;
    }

    // Substract completed payments from total remaining balance.
    foreach ($this->paymentStorage->loadMultipleByOrder($order) as $payment) {
      if ($payment->isCompleted()) {
        $remainingBalance = $remainingBalance->subtract($payment->getAmount());
      }
    }

    // Create capture with remaining balanace.
    if (!$remainingBalance->isZero() && $remainingBalance->isPositive()) {
      // Attempt to use local payment made in Klarna::onReturn().
      $payment = $this->getPayment($order) ?? $plugin->createPayment($order);

      $plugin->capturePayment($payment, $remainingBalance);
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
      $events[sprintf('commerce_order.%s.pre_transition', $transition)] = [['onOrderPlace']];
    }
    $events['commerce_order.place.post_transition'][] = ['updateOrderNumberOnPlace'];
    return $events;
  }

}
