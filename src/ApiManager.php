<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use GuzzleHttp\ClientInterface;
use Klarna\OrderManagement\Api\CapturesApi;
use Klarna\OrderManagement\Api\OrdersApi;
use Klarna\Payments\Api\OrdersApi as PaymentOrdersApi;
use Klarna\Payments\Api\SessionsApi;
use Klarna\ApiException;
use Klarna\OrderManagement\Model\Capture;
use Klarna\Payments\Model\CreateOrderRequest;
use Klarna\OrderManagement\Model\Order;
use Klarna\Payments\Model\Order as PaymentOrder;
use Klarna\Payments\Model\Session;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Klarna\OrderManagement\Api\RefundsApi;
use Klarna\OrderManagement\Model\RefundObject;

/**
 * Provides a service to interact with Klarna.
 */
final class ApiManager implements ApiManagerInterface {

  use ObjectSerializerTrait;
  use PaymentGatewayPluginTrait;

  /**
   * Constructs a new instance.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder $requestBuilder
   *   The request builder.
   * @param \GuzzleHttp\ClientInterface $client
   *   The client.
   */
  public function __construct(
    private EventDispatcherInterface $eventDispatcher,
    private RequestBuilder $requestBuilder,
    private ClientInterface $client
  ) {
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function getOrder(OrderInterface $order) : ? Order {
    if (!$orderId = $order->getData('klarna_order_id')) {
      throw new NonKlarnaOrderException('Klarna order not found.');
    }

    return $this->getOrderManagementApi($order)
      ->getOrder($orderId);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Klarna\ApiException
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function createCapture(OrderInterface $order, Price $amount = NULL) : Capture {
    $orderResponse = $this->getOrder($order);
    $currentCaptures = $orderResponse->getCaptures();

    $capture = $this->requestBuilder
      ->createCaptureRequest($order);

    $capture = $this->eventDispatcher
      ->dispatch(new RequestEvent($order, $capture), Events::CAPTURE_CREATE)
      ->getData();

    if (!$capture instanceof Capture) {
      throw new \InvalidArgumentException('Capture is not set.');
    }

    if ($amount) {
      $capture->setCapturedAmount(UnitConverter::toAmount($amount));
    }

    $this->getCapturesApi($order)
      ->captureOrder($orderResponse->getOrderId(), $capture);

    $orderResponse = $this->getOrder($order);
    // Create capture request doesn't return the capture it made.
    // We should not have any recaptures before this, but re-fetch the order and
    // compare captures to previously fetched captures just to be sure.
    $newCaptures = array_filter($orderResponse->getCaptures(), function (Capture $newCapture) use ($currentCaptures) {
      foreach ($currentCaptures as $oldCapture) {
        // We only care about newly made captures.
        if ($oldCapture->getCaptureId() === $newCapture->getCaptureId()) {
          return FALSE;
        }
      }
      return TRUE;
    });

    return reset($newCaptures);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function orderIsInSync(OrderInterface $order, Order $orderResponse = NULL) : bool {
    if (!$orderResponse) {
      $orderResponse = $this->getOrder($order);
    }

    return UnitConverter::toPrice($orderResponse->getOrderAmount(), $order->getTotalPrice()->getCurrencyCode())
      ->equals($order->getTotalPrice());

  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function releaseRemainingAuthorization(OrderInterface $order, Order $orderResponse = NULL) : void {
    if (!$orderResponse) {
      $orderResponse = $this->getOrder($order);
    }
    $this->eventDispatcher
      ->dispatch(
        new RequestEvent($order, $orderResponse), Events::RELEASE_REMAINING_AUTHORIZATION
      );
    $this
      ->getOrderManagementApi($order)
      ->releaseRemainingAuthorization($orderResponse->getOrderId());
  }

  /**
   * {@inheritdoc}
   */
  public function acknowledgeOrder(OrderInterface $order, Order $orderResponse = NULL) : void {
    if (!$orderResponse) {
      $orderResponse = $this->getOrder($order);
    }
    $this->eventDispatcher
      ->dispatch(new RequestEvent($order, $orderResponse), Events::ACKNOWLEDGE_ORDER);

    $this->getOrderManagementApi($order)->acknowledgeOrder($orderResponse->getOrderId(), $order->uuid());
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function voidPayment(OrderInterface $order) : void {
    $orderResponse = $this->getOrder($order);

    $this->eventDispatcher
      ->dispatch(new RequestEvent($order, $orderResponse), Events::VOID_PAYMENT);

    $this->getOrderManagementApi($order)
      ->cancelOrder($orderResponse->getOrderId());
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function refundPayment(OrderInterface $order, Price $amount, string $klarna_idempotency_key) : void {
    $orderResponse = $this->getOrder($order);
    $body = new RefundObject([
      'refunded_amount' => UnitConverter::toAmount($amount),
    ]);
    $this->eventDispatcher
      ->dispatch(new RequestEvent($order, $orderResponse), Events::REFUND_CREATE);

    $this->getRefundsApi($order)
      ->refundOrder($orderResponse->getOrderId(), $klarna_idempotency_key, $body);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function authorizeOrder(OrderInterface $order, string $token) : PaymentOrder {
    $session = $this->requestBuilder
      ->createSessionRequest($order);

    $createOrderRequest = $this->eventDispatcher
      ->dispatch(new RequestEvent($order, $session), Events::ORDER_CREATE)
      ->getData();

    if ($createOrderRequest instanceof Session) {
      // Session and CreateOrderRequest have identical fields.
      // Convert Session request to CreateOrderRequest.
      $createOrderRequest = new CreateOrderRequest($this->modelToArray($createOrderRequest));
    }

    $request = $this->getPaymentOrderApi($order);
    $response = $request->createOrder($token, $createOrderRequest);

    $order->setData('klarna_order_id', $response->getOrderId())
      ->save();

    return $response;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   * @throws \InvalidArgumentException
   */
  public function handleNotificationEvent(OrderInterface $order, string $fraudStatus) : void {
    $orderResponse = $this->getOrder($order);

    $statusMap = [
      'FRAUD_RISK_ACCEPTED' => Events::FRAUD_NOTIFICATION_ACCEPTED,
      'FRAUD_RISK_REJECTED' => Events::FRAUD_NOTIFICATION_REJECTED,
      'FRAUD_RISK_STOPPED' => Events::FRAUD_NOTIFICATION_STOPPED,
    ];

    if (!isset($statusMap[$fraudStatus])) {
      throw new \InvalidArgumentException('Invalid fraud status.');
    }

    $this->eventDispatcher
      ->dispatch(new RequestEvent($order, $orderResponse), $statusMap[$fraudStatus]);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getSessionsApi(OrderInterface $order) : SessionsApi {
    return new SessionsApi($this->client, $this->getPlugin($order)->getClientConfiguration());
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getCapturesApi(OrderInterface $order) : CapturesApi {
    return new CapturesApi($this->client, $this->getPlugin($order)->getClientConfiguration());
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getPaymentOrderApi(OrderInterface $order) : PaymentOrdersApi {
    return new PaymentOrdersApi($this->client, $this->getPlugin($order)->getClientConfiguration());
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getOrderManagementApi(OrderInterface $order) : OrdersApi {
    return new OrdersApi($this->client, $this->getPlugin($order)->getClientConfiguration());
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getRefundsApi(OrderInterface $order) : RefundsApi {
    return new RefundsApi($this->client, $this->getPlugin($order)->getClientConfiguration());
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Klarna\ApiException
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function sessionRequest(OrderInterface $order) : Session {
    $session = $this->requestBuilder
      ->createSessionRequest($order);

    $session = $this->eventDispatcher
      ->dispatch(new RequestEvent($order, $session), Events::SESSION_CREATE)
      ->getData();

    if (!$session instanceof Session) {
      throw new \InvalidArgumentException('Session is not set.');
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
    catch (ApiException) {
      // Attempt to create a new session if update failed.
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
