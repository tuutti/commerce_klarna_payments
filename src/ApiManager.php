<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\Exception\FraudException;
use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use InvalidArgumentException;
use Klarna\Api\CapturesApi;
use Klarna\Api\OrdersApi;
use Klarna\Api\PaymentOrdersApi;
use Klarna\Api\SessionsApi;
use Klarna\ApiException;
use Klarna\Model\Capture;
use Klarna\Model\CreateOrderRequest;
use Klarna\Model\Order;
use Klarna\Model\PaymentOrder;
use Klarna\Model\Session;
use Klarna\ObjectSerializer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides a service to interact with Klarna.
 */
final class ApiManager {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventSubscriberInterface
   */
  protected $eventDispatcher;

  /**
   * Constructs a new instance.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   */
  public function __construct(EventDispatcherInterface $eventDispatcher) {
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * Gets the plugin for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   *   The payment plugin.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException|
   */
  public function getPlugin(OrderInterface $order) : ? Klarna {
    $gateway = $order->get('payment_gateway');

    if ($gateway->isEmpty()) {
      throw new InvalidArgumentException('Payment gateway not found.');
    }
    $plugin = $gateway->first()->entity->getPlugin();

    if (!$plugin instanceof Klarna) {
      throw new NonKlarnaOrderException('Payment gateway not instanceof Klarna.');
    }
    return $plugin;
  }

  /**
   * Gets the Klarna order for given commerce order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Model\Order
   *   The klarna order response.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function getOrder(OrderInterface $order) : Order {
    if (!$orderId = $order->getData('klarna_order_id')) {
      throw new \InvalidArgumentException('Order ID not set.');
    }

    return $this->getOrderManagementApi($order)
      ->getOrder($orderId);
  }

  /**
   * Creates a capture for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_price\Price|null $amount
   *   The amount.
   *
   * @return \Klarna\Model\Capture
   *   The capture.
   */
  public function createCapture(OrderInterface $order, Price $amount = NULL) : Capture {
    $orderResponse = $this->getOrder($order);
    $currentCaptures = $orderResponse->getCaptures();

    $capture = $this->eventDispatcher
      ->dispatch(Events::CAPTURE_CREATE, new RequestEvent($order, new Capture()))
      ->getData();

    if ($amount) {
      $capture->setCapturedAmount(UnitConverter::toAmount($amount));
    }

    $request = $this->getCapturesApi($order);
    $request->captureOrder($orderResponse->getOrderId(), $capture);

    $orderResponse = $this->getOrder($order);
    // Create capture request doesn't return the saved capture.
    // We should'nt have any recaptures before this, but re-fetch the order and
    // compare captures to previously fetched captures just to be sure.
    $newCaptures = array_diff($orderResponse->getCaptures(), $currentCaptures);

    return reset($newCaptures);
  }

  /**
   * Releases the remaining authorizations for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to release authorizations.
   * @param \Klarna\Model\Order|null $orderResponse
   *   The order response.
   */
  public function releaseRemainingAuthorization(OrderInterface $order, Order $orderResponse = NULL) : void {
    $this->eventDispatcher
      ->dispatch(Events::RELEASE_REMAINING_AUTHORIZATION, new RequestEvent($order));

    if (!$orderResponse) {
      $orderResponse = $this->getOrder($order);
    }

    $this
      ->getOrderManagementApi($order)
      ->releaseRemainingAuthorization($orderResponse->getOrderId());
  }

  /**
   * Acknowledges the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Klarna\Model\Order|null $orderResponse
   *   The order response.
   */
  public function acknowledgeOrder(OrderInterface $order, Order $orderResponse = NULL) : void {
    $this->eventDispatcher
      ->dispatch(Events::ACKNOWLEDGE_ORDER, new RequestEvent($order));

    if (!$orderResponse) {
      $orderResponse = $this->getOrder($order);
    }

    $this->getOrderManagementApi($order)->acknowledgeOrder($orderResponse->getOrderId());
  }

  /**
   * Voids payment for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  public function voidPayment(OrderInterface $order) : void {
    $this->eventDispatcher
      ->dispatch(Events::VOID_PAYMENT, new RequestEvent($order));

    $orderResponse = $this->getOrder($order);

    // @todo Find out, if a DELETE /payments/v1/authorizations/{authorizationToken}
    // request would be better here.
    $this->getOrderManagementApi($order)->cancelOrder($orderResponse->getOrderId());
  }

  /**
   * Authorizes the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $authorizeToken
   *   The authorize token.
   *
   * @return \Klarna\Model\PaymentOrder
   *   The authorization response.
   */
  public function authorizeOrder(OrderInterface $order, string $authorizeToken) : PaymentOrder {
    $createOrderRequest = $this->eventDispatcher
      ->dispatch(Events::ORDER_CREATE, new RequestEvent($order, new Session()))
      ->getData();

    if ($createOrderRequest instanceof Session) {
      // Convert Session request to CreateOrderRequest.
      $createOrderRequest = (array) ObjectSerializer::sanitizeForSerialization($createOrderRequest);
      $createOrderRequest = new CreateOrderRequest($createOrderRequest);
    }

    $request = $this->getPaymentOrderApi($order);
    $response = $request->createOrder($authorizeToken, $createOrderRequest);

    if (!in_array($response->getFraudStatus(), ['ACCEPTED', 'PENDING'])) {
      throw new FraudException(sprintf('Fraud validation failed for order %s.', $order->id()));
    }
    $order->setData('klarna_order_id', $response->getOrderId())
      ->save();

    return $response;
  }

  /**
   * Gets the sessions request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Api\SessionsApi
   *   The Sessions api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getSessionsApi(OrderInterface $order) : SessionsApi {
    $plugin = $this->getPlugin($order);
    return new SessionsApi(NULL, $plugin->getClientConfiguration());
  }

  /**
   * Gets the captures api request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Api\CapturesApi
   *   The captures api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getCapturesApi(OrderInterface $order) : CapturesApi {
    $plugin = $this->getPlugin($order);
    return new CapturesApi(NULL, $plugin->getClientConfiguration());
  }

  /**
   * Gets the payment orders api request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Api\PaymentOrdersApi
   *   The payment order api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getPaymentOrderApi(OrderInterface $order) : PaymentOrdersApi {
    $plugin = $this->getPlugin($order);
    return new PaymentOrdersApi(NULL, $plugin->getClientConfiguration());
  }

  /**
   * Gets the order management orders api request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Api\OrdersApi
   *   The orders api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getOrderManagementApi(OrderInterface $order) : OrdersApi {
    $plugin = $this->getPlugin($order);
    return new OrdersApi(NULL, $plugin->getClientConfiguration());
  }

  /**
   * Builds a new order transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Model\Session
   *   The session response.
   */
  public function buildTransaction(OrderInterface $order) : Session {
    $session = $this->eventDispatcher
      ->dispatch(Events::SESSION_CREATE, new RequestEvent($order, new Session()))
      ->getData();

    if (!$session instanceof Session) {
      throw new InvalidArgumentException('Session is not set.');
    }
    $sessionId      = $order->getData('klarna_session_id');
    $sessionRequest = $this->getSessionsApi($order);

    try {
      // Attempt to use already stored session id to update.
      if ($sessionId) {
        $sessionRequest->updateCreditSession($sessionId, $session);
        $sessionResponse = $sessionRequest->readCreditSession($sessionId);
      }
      else {
        $sessionResponse = $sessionRequest->createCreditSession($session);
      }
    }
    catch (ApiException $e) {
      // Attempt to create new session session if update failed.
      // This usually happens when we have an expired session id
      // saved in commerce order.
      $sessionResponse = $sessionRequest->createCreditSession($session);
    }

    // Session id will only be set when we create session with
    // ::createCreditSession().
    if (isset($sessionResponse['session_id'])) {
      $order->setData('klarna_session_id', $sessionResponse['session_id'])
        ->save();

      // ::createCreditSession() will return MerchantSession object. Make sure
      // we return Session object by re-reading the session from Klarna API.
      $sessionResponse = $sessionRequest->readCreditSession($sessionResponse['session_id']);
    }

    return $sessionResponse;
  }

}
