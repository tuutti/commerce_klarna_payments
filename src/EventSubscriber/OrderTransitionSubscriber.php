<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\Connector;
use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles order capturing when the order is placed.
 */
class OrderTransitionSubscriber implements EventSubscriberInterface {

  /**
   * The klarna connector.
   *
   * @var \Drupal\commerce_klarna_payments\Connector
   */
  protected $connector;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  protected $paymentStorage;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_klarna_payments\Connector $connector
   *   The Klarna connector.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(Connector $connector, LoggerInterface $logger, EventDispatcherInterface $eventDispatcher, EntityTypeManagerInterface $entityTypeManager) {
    $this->connector = $connector;
    $this->logger = $logger;
    $this->eventDispatcher = $eventDispatcher;
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
   * Creates payment for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createPaymentCapture(OrderInterface $order) : void {
    $orderRequest = $this->connector->getOrder($order);

    // No payment found. This usually happens when we have done a partial
    // capture (manually).
    if (!$payment = $this->getPayment($order)) {
      $payments = $this->paymentStorage->loadMultipleByOrder($order);
      $orderRequest->fetch();

      // Release the remaining authorization in case we have at least
      // one capture made already.
      if (!empty($orderRequest['captures']) && !empty($payments)) {
        $orderRequest->releaseRemainingAuthorization();

        $this->eventDispatcher
          ->dispatch(Events::RELEASE_REMAINING_AUTHORIZATION, new RequestEvent($order, (array) $orderRequest));

        return;
      }
      throw new InvalidArgumentException('Payment not found.');
    }
    $captureData = $this->eventDispatcher
      ->dispatch(Events::CAPTURE_CREATE, new RequestEvent($order))
      ->getData();

    $capture = $orderRequest->createCapture($captureData);
    $capture->fetch();

    // Transition payment to captured state.
    $transition = $payment->getState()
      ->getWorkflow()
      ->getTransition('capture');

    $payment->getState()->applyTransition($transition);

    $payment
      ->setRemoteId($capture['capture_id'])
      ->save();
  }

  /**
   * This method is called whenever the order is completed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The transition event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) : void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // This should only be triggered when the order is being completed.
    if ($event->getTransition()->getToState()->getId() !== 'completed') {
      return;
    }

    // If the order is already paid in full, there's no need for trying to
    // capture the payment again.
    if ($order->isPaid()) {
      return;
    }

    try {
      $this->createPaymentCapture($order);
    }
    catch (NonKlarnaOrderException $e) {
      return;
    }
    catch (Exception $e) {
      $this->logger->critical(new TranslatableMarkup('Payment capture for order @order failed: @message', [
        '@order' => $event->getEntity()->id(),
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

      if (empty($orderRequest['merchant_reference1'])) {
        // Set the order number.
        $orderRequest->updateMerchantReferences([
          'merchant_reference1' => $order->getOrderNumber(),
        ]);
      }
    }
    catch (NonKlarnaOrderException $e) {
      return;
    }
    catch (Exception $e) {
      $this->logger->warning(new TranslatableMarkup('Setting Klarna order number failed for order @order: @message', [
        '@order' => $order->id(),
        '@message' => $e->getMessage(),
      ]));
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
