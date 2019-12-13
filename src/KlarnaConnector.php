<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\commerce_order\Entity\OrderInterface;
use GuzzleHttp\Exception\ClientException;
use InvalidArgumentException;
use Klarna\Rest\OrderManagement\Order;
use Klarna\Rest\Transport\ConnectorInterface;
use Klarna\Rest\Transport\Exception\ConnectorException;
use Klarna\Rest\Transport\GuzzleConnector;
use Klarna\Rest\Transport\UserAgent;
use KlarnaPayments\Exception\FraudException;
use KlarnaPayments\Request\Payment\Order\OrderRequest;
use KlarnaPayments\Request\Payment\Session\SessionRequest;
use KlarnaPayments\Response\Payment\Order\AuthorizationResponse;
use KlarnaPayments\Response\Payment\Session\SessionResponse;
use LogicException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides a service to interact with Klarna.
 */
final class KlarnaConnector {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventSubscriberInterface
   */
  protected $eventDispatcher;

  /**
   * The connector.
   *
   * @var \Klarna\Rest\Transport\Connector
   */
  protected $connector;

  /**
   * Constructs a new instance.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Klarna\Rest\Transport\ConnectorInterface|null $connector
   *   The connector.
   */
  public function __construct(EventDispatcherInterface $eventDispatcher, ConnectorInterface $connector = NULL) {
    $this->eventDispatcher = $eventDispatcher;
    $this->connector = $connector;
  }

  /**
   * Gets the connector.
   *
   * @param \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna $plugin
   *   The plugin.
   *
   * @return \Klarna\Rest\Transport\ConnectorInterface
   *   The connector.
   */
  protected function getConnector(Klarna $plugin) : ConnectorInterface {
    if (!$this->connector) {
      $ua = (new UserAgent())
        ->setField('Library', 'drupal-klarna-payments-v1');

      // Populate default connector.
      $this->connector = GuzzleConnector::create(
        $plugin->getUsername(),
        $plugin->getPassword(),
        $plugin->getApiUri(),
        $ua
      );
    }
    return $this->connector;
  }

  /**
   * Gets the plugin for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   *   The payment plugin.
   */
  public function getPlugin(OrderInterface $order) : Klarna {
    $gateway = $order->get('payment_gateway');

    if ($gateway->isEmpty()) {
      throw new InvalidArgumentException('Payment gateway not found.');
    }
    $plugin = $gateway->first()->entity->getPlugin();

    if (!$plugin instanceof Klarna) {
      throw new InvalidArgumentException('Payment gateway not instanceof Klarna.');
    }
    return $plugin;
  }

  /**
   * Gets the Klarna order for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Rest\OrderManagement\Order
   *   The klarna order.
   */
  public function getOrder(OrderInterface $order) : Order {
    $plugin = $this->getPlugin($order);

    if (!$orderId = $order->getData('klarna_order_id')) {
      throw new InvalidArgumentException('Klarna order ID is not set.');
    }

    try {
      $order = new Order($this->getConnector($plugin), $orderId);
      $order->fetch();

      if (!isset($order['status'])) {
        throw new InvalidArgumentException('Order status not found.');
      }
    }
    catch (ConnectorException $e) {
      throw new InvalidArgumentException($e->getMessage());
    }

    return $order;
  }

  /**
   * Authorizes the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $authorizeToken
   *   The authorize token.
   *
   * @return \KlarnaPayments\Response\Payment\Order\AuthorizationResponse
   *   The authorization response.
   */
  public function authorizeOrder(OrderInterface $order, string $authorizeToken) : AuthorizationResponse {
    $plugin = $this->getPlugin($order);

    /** @var \KlarnaPayments\Data\Payment\Order\Order $data */
    $data = $this->eventDispatcher
      ->dispatch(Events::ORDER_CREATE, new RequestEvent($order))
      ->getObject();

    if (!$data->getAuthorizationToken()) {
      $data->setAuthorizationToken($authorizeToken);
    }
    $request = new OrderRequest($this->getConnector($plugin));
    $response = $request->create($data);

    if (!$response->isAccepted() && !$response->isPending()) {
      throw new FraudException('Fraud validation failed.');
    }
    $order->setData('klarna_order_id', $response->getOrderId())
      ->save();

    return $response;
  }

  /**
   * Builds a new order transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \KlarnaPayments\Response\Payment\Session\SessionResponse
   *   The Klarna request data.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function buildTransaction(OrderInterface $order) : SessionResponse {
    $plugin = $this->getPlugin($order);

    $session = $this->eventDispatcher
      ->dispatch(Events::SESSION_CREATE, new RequestEvent($order))
      ->getObject();

    if (!$session) {
      throw new LogicException('Session is not set.');
    }

    $sessionRequest = new SessionRequest($this->getConnector($plugin));
    // Attempt to use already stored session id.
    $sessionId = $order->getData('klarna_session_id', NULL);

    try {
      $sessionResponse = $sessionId ?
        $sessionRequest->update($session, $sessionId) : $sessionRequest->create($session);
    }
    catch (ConnectorException | ClientException $e) {
      // Attempt to create new session session if update failed.
      // This usually happens when we have an expired session id
      // saved in commerce order.
      $sessionResponse = $sessionRequest->create($session);
    }

    // Session id will be reset when we update the session and
    // re-fetch. Update only if session id has changed.
    if ($sessionId !== $sessionResponse->getSessionId()) {
      $order->setData('klarna_session_id', $sessionResponse->getSessionId())
        ->save();
    }

    return $sessionResponse;
  }

}
