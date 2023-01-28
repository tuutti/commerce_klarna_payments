<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Controller;

use Drupal\commerce_checkout\CheckoutOrderManager;
use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handle Klarna push notifications.
 */
class PushEndpointController implements ContainerInjectionInterface {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * The checkout manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManager
   */
  protected $checkoutOrderManager;

  /**
   * The api manager.
   *
   * @var \Drupal\commerce_klarna_payments\ApiManager
   */
  protected $apiManager;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannel|\Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_checkout\CheckoutOrderManager $checkoutOrderManager
   *   The checkout order manager.
   * @param \Drupal\commerce_klarna_payments\ApiManager $apiManager
   *   The api manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannel $logger
   *   Logger.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   Event dispatcher.
   */
  public function __construct(
    CheckoutOrderManager $checkoutOrderManager,
    ApiManager $apiManager,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannel $logger,
    EventDispatcherInterface $dispatcher
  ) {
    $this->checkoutOrderManager = $checkoutOrderManager;
    $this->apiManager = $apiManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
    $this->eventDispatcher = $dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_checkout.checkout_order_manager'),
      $container->get('commerce_klarna_payments.api_manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.commerce_klarna_payments'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * Validates the authorization and handles the push.
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
  public function handleRequest(OrderInterface $commerce_order, PaymentGatewayInterface $commerce_payment_gateway, Request $request) {
    $klarna_order_id = $request->query->get('klarna_order_id');

    if (empty($klarna_order_id)) {
      throw new AccessDeniedHttpException('Required parameters missing.');
    }

    if ($klarna_order_id !== $commerce_order->getData('klarna_order_id')) {
      throw new AccessDeniedHttpException('Required parameters missing.');
    }

    try {
      $klarna_order = $this->apiManager
        ->getOrderManagementApi($commerce_order)
        ->getOrder($klarna_order_id);

      // If the order was already paid (ie user came back before finishing
      // Klarna's workflow and choose a different gateway).
      if ($commerce_order->isPaid()) {
        throw new AccessDeniedHttpException('Already paid.');
      }

      // Check the state of the order.
      $allowed = ['AUTHORIZED', 'PART_CAPTURED', 'CAPTURED'];
      if (!in_array($klarna_order->getStatus(), $allowed)) {
        throw new AccessDeniedHttpException('Order is in invalid state.');
      }

      $this->eventDispatcher->dispatch(new RequestEvent(Events::PUSH_ENDPOINT_CALLED, $commerce_order, $klarna_order));

      // Create a payment if none exist.
      /** @var \Drupal\commerce_payment\PaymentStorageInterface $commerce_payment_storage */
      $commerce_payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $existing_payments = $commerce_payment_storage->loadByProperties([
        'payment_gateway' => $commerce_payment_gateway->id(),
        'order_id' => $commerce_order->id(),
      ]);

      if (!$existing_payments) {
        /** @var \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna $klarna_plugin */
        $klarna_plugin = $commerce_payment_gateway->getPlugin();
        $klarna_plugin->getOrCreatePayment($commerce_order)->save();
        $this->logger->info($this->t('Webhook: created payment for order %id (Klarna id: %klarna_id)', [
          '%id' => $commerce_order->id(),
          '%klarna_id' => $klarna_order_id,
        ]));
      }

      // We acknowledge the order, because if the webhook was fired, the order
      // was maybe not acknowledged on return.
      $this->apiManager->acknowledgeOrder($commerce_order, $klarna_order);
      return new Response('Ok');
    }
    catch (\Exception $e) {
      throw new AccessDeniedHttpException($e->getMessage());
    }
  }

}
