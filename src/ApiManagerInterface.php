<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Klarna\OrderManagement\Api\CapturesApi;
use Klarna\OrderManagement\Api\OrdersApi;
use Klarna\OrderManagement\Api\RefundsApi;
use Klarna\OrderManagement\Model\Capture;
use Klarna\OrderManagement\Model\Order;
use Klarna\Payments\Api\OrdersApi as PaymentOrdersApi;
use Klarna\Payments\Api\SessionsApi;
use Klarna\Payments\Model\Order as PaymentOrder;
use Klarna\Payments\Model\Session;

/**
 * Provides an interface for ApiManager.
 */
interface ApiManagerInterface {

  /**
   * Gets the Klarna order for given commerce order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\OrderManagement\Model\Order|null
   *   The klarna order response.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   */
  public function getOrder(OrderInterface $order) : ? Order;

  /**
   * Creates a capture for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_price\Price|null $amount
   *   The amount.
   *
   * @return \Klarna\OrderManagement\Model\Capture
   *   The capture.
   */
  public function createCapture(OrderInterface $order, Price $amount = NULL) : Capture;

  /**
   * Releases the remaining authorizations for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to release authorizations.
   * @param \Klarna\OrderManagement\Model\Order|null $orderResponse
   *   The order response.
   */
  public function releaseRemainingAuthorization(OrderInterface $order, Order $orderResponse = NULL) : void;

  /**
   * Acknowledges the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Klarna\OrderManagement\Model\Order|null $orderResponse
   *   The order response.
   */
  public function acknowledgeOrder(OrderInterface $order, Order $orderResponse = NULL) : void;

  /**
   * Voids payment for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  public function voidPayment(OrderInterface $order) : void;

  /**
   * Refund payment for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_price\Price $amount
   *   The order.
   * @param string $klarna_idempotency_key
   *   The key to guarantee the idempotency of the operation. The key
   *   should be unique. Retries of requests are safe to be applied in case
   *   of errors.
   */
  public function refundPayment(OrderInterface $order, Price $amount, string $klarna_idempotency_key) : void;

  /**
   * Authorizes the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $token
   *   The authorization token.
   *
   * @return \Klarna\Payments\Model\Order
   *   The authorization response.
   */
  public function authorizeOrder(OrderInterface $order, string $token) : PaymentOrder;

  /**
   * Handles to notification event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $fraudStatus
   *   The fraud status.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   * @throws \Klarna\ApiException
   * @throws \InvalidArgumentException
   */
  public function handleNotificationEvent(OrderInterface $order, string $fraudStatus) : void;

  /**
   * Gets the sessions request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Payments\Api\SessionsApi
   *   The Sessions api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getSessionsApi(OrderInterface $order) : SessionsApi;

  /**
   * Gets the captures api request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\OrderManagement\Api\CapturesApi
   *   The captures api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getCapturesApi(OrderInterface $order) : CapturesApi;

  /**
   * Gets the payment orders api request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Payments\Api\OrdersApi
   *   The payment order api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getPaymentOrderApi(OrderInterface $order) : PaymentOrdersApi;

  /**
   * Gets the order management orders api request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\OrderManagement\Api\OrdersApi
   *   The orders api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getOrderManagementApi(OrderInterface $order) : OrdersApi;

  /**
   * Gets the refund api request.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\OrderManagement\Api\RefundsApi
   *   The refunds api request.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getRefundsApi(OrderInterface $order) : RefundsApi;

  /**
   * Builds a new order transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Payments\Model\Session
   *   The session response.
   */
  public function sessionRequest(OrderInterface $order) : Session;

}
