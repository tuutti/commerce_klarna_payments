<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Controller;

use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_klarna_payments\ApiManagerInterface;
use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\PaymentGatewayPluginTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Klarna\ApiException;
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
  use PaymentGatewayPluginTrait;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_checkout\CheckoutOrderManagerInterface $checkoutOrderManager
   *   The checkout order manager.
   * @param \Drupal\commerce_klarna_payments\ApiManagerInterface $apiManager
   *   The api manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Logger.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   */
  public function __construct(
    protected CheckoutOrderManagerInterface $checkoutOrderManager,
    protected ApiManagerInterface $apiManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
    protected EventDispatcherInterface $eventDispatcher
  ) {
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
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function handleRequest(OrderInterface $commerce_order, PaymentGatewayInterface $commerce_payment_gateway, Request $request) : Response {
    $klarna_order_id = $request->query->get('klarna_order_id');

    if (empty($klarna_order_id) || $klarna_order_id !== $commerce_order->getData('klarna_order_id')) {
      return new Response('Klarna order id does not match.');
    }

    $orderResponse = $this->apiManager
      ->getOrder($commerce_order);

    // If the order was already paid (i.e. user came back before finishing
    // Klarna's workflow and choose a different gateway).
    if ($commerce_order->isPaid()) {
      return new Response('Order is paid already.');
    }

    // Check the state of the order.
    $allowed = ['AUTHORIZED', 'PART_CAPTURED', 'CAPTURED'];
    if (!in_array($orderResponse->getStatus(), $allowed)) {
      throw new AccessDeniedHttpException('Order is in invalid state.');
    }

    $this->eventDispatcher->dispatch(new RequestEvent($commerce_order, $orderResponse), Events::PUSH_ENDPOINT_CALLED);

    $klarna_plugin = $this->getPlugin($commerce_order);
    $klarna_plugin->getOrCreatePayment($commerce_order);

    // We acknowledge the order, because if the webhook was fired, the order
    // was maybe not acknowledged on return.
    try {
      $this->apiManager->acknowledgeOrder($commerce_order, $orderResponse);
    }
    catch (ApiException) {
      throw new AccessDeniedHttpException('Failed to acknowledge order.');
    }
    return new Response('Ok');
  }

}
