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
use GuzzleHttp\Exception\ClientException;
use InvalidArgumentException;
use Klarna\Rest\OrderManagement\Capture;
use Klarna\Rest\OrderManagement\Order;
use Klarna\Rest\Payments\Orders;
use Klarna\Rest\Payments\Sessions;
use Klarna\Rest\Transport\ConnectorInterface;
use Klarna\Rest\Transport\Exception\ConnectorException;
use Klarna\Rest\Transport\GuzzleConnector;
use Klarna\Rest\Transport\UserAgent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides a service to interact with Klarna.
 */
final class Connector {

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
   * Creates a capture for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_price\Price $amount
   *   The amount.
   *
   * @return \Klarna\Rest\OrderManagement\Capture
   *   The capture.
   */
  public function createCapture(OrderInterface $order, Price $amount) : Capture {
    $orderRequest = $this->getOrder($order);
    $request = $this->eventDispatcher
      ->dispatch(Events::CAPTURE_CREATE, new RequestEvent($order))
      ->getData();

    $request['captured_amount'] = UnitConverter::toAmount($amount);

    $capture = $orderRequest->createCapture($request);
    $capture->fetch();

    return $capture;
  }

  /**
   * Authorizes the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $authorizeToken
   *   The authorize token.
   *
   * @return array
   *   The authorization response.
   */
  public function authorizeOrder(OrderInterface $order, string $authorizeToken) : array {
    $plugin = $this->getPlugin($order);

    $data = $this->eventDispatcher
      ->dispatch(Events::ORDER_CREATE, new RequestEvent($order))
      ->getData();

    $request = new Orders($this->getConnector($plugin), $authorizeToken);
    $response = (array) $request->create($data);

    if (!in_array($response['fraud_status'], ['ACCEPTED', 'PENDING'])) {
      throw new FraudException('Fraud validation failed.');
    }
    $order->setData('klarna_order_id', $response['order_id'])
      ->save();

    return $response;
  }

  /**
   * Builds a new order transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The Klarna request data.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function buildTransaction(OrderInterface $order) : array {
    $plugin = $this->getPlugin($order);

    $session = $this->eventDispatcher
      ->dispatch(Events::SESSION_CREATE, new RequestEvent($order))
      ->getData();

    if (!$session) {
      throw new InvalidArgumentException('Session is not set.');
    }
    $sessionId      = $order->getData('klarna_session_id');
    $connector      = $this->getConnector($plugin);
    $sessionRequest = new Sessions($connector);

    try {
      // Attempt to use already stored session id to update.
      if ($sessionId) {
        $sessionResponse = (array) (new Sessions($connector, $sessionId))
          ->update($session)
          ->fetch();
      }
      else {
        $sessionResponse = (array) $sessionRequest->create($session);
      }
    }
    catch (ConnectorException | ClientException $e) {
      // Attempt to create new session session if update failed.
      // This usually happens when we have an expired session id
      // saved in commerce order.
      $sessionResponse = (array) $sessionRequest->create($session);
    }

    // Session id will be reset when we update the session and
    // re-fetch. Update only if session id has changed.
    if ($sessionId !== $sessionResponse['session_id']) {
      $order->setData('klarna_session_id', $sessionResponse['session_id'])
        ->save();
    }

    return (array) $sessionResponse;
  }

}
