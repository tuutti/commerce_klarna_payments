<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\SessionEvent;
use Drupal\commerce_klarna_payments\Klarna\AuthorizationResponse;
use Drupal\commerce_klarna_payments\Klarna\Exception\FraudException;
use Drupal\commerce_klarna_payments\Klarna\Rest\Authorization;
use Drupal\commerce_klarna_payments\Klarna\Rest\Session;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\commerce_order\Entity\OrderInterface;
use GuzzleHttp\Exception\ClientException;
use Klarna\Rest\Transport\Connector;
use Klarna\Rest\Transport\Exception\ConnectorException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides a service to interact with Klarna.
 */
final class KlarnaConnector {

  /**
   * The payment plugin.
   *
   * @var \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   */
  protected $plugin;

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
   * @param \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna $plugin
   *   The payment plugin.
   * @param \Klarna\Rest\Transport\Connector|null $connector
   *   The connector.
   */
  public function __construct(EventDispatcherInterface $eventDispatcher, Klarna $plugin, Connector $connector = NULL) {
    $this->eventDispatcher = $eventDispatcher;
    $this->plugin = $plugin;
    $this->connector = $connector;
  }

  /**
   * Gets the connector.
   *
   * @return \Klarna\Rest\Transport\Connector
   *   The connector.
   */
  protected function getConnector() : Connector {
    if (!$this->connector) {
      // Populate default connector.
      $this->connector = Connector::create(
        $this->plugin->getUsername(),
        $this->plugin->getPassword(),
        $this->plugin->getApiUri()
      );
    }
    return $this->connector;
  }

  /**
   * Gets the Klarna session.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Rest\Session
   *   The Klarna session.
   */
  protected function getSession(OrderInterface $order) : Session {
    // Attempt to use already stored session id.
    $sessionId = $order->getData('klarna_session_id', NULL);

    return new Session($this->getConnector(), $sessionId);
  }

  /**
   * Authorizes the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $authorizeToken
   *   The authorize token.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\AuthorizationResponse
   *   The authorization response.
   *
   * @throws \Drupal\commerce_klarna_payments\Klarna\Exception\FraudException
   */
  public function authorizeOrder(OrderInterface $order, string $authorizeToken) : AuthorizationResponse {
    /** @var \Drupal\commerce_klarna_payments\Event\SessionEvent $event */
    $event = $this->eventDispatcher
      ->dispatch(Events::ORDER_CREATE, new SessionEvent($order));
    $build = $event->getRequest()->toArray();

    $authorizer = new Authorization($this->getConnector(), $authorizeToken);
    $authorizer->create($build);

    if (!isset($authorizer['fraud_status'], $authorizer['redirect_url'], $authorizer['order_id'])) {
      throw new \InvalidArgumentException('Authorization validation failed.');
    }

    $response = AuthorizationResponse::createFromRequest($authorizer);

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
   * @return array
   *   The Klarna request data.
   */
  public function buildTransaction(OrderInterface $order) : array {
    /** @var \Drupal\commerce_klarna_payments\Event\SessionEvent $event */
    $event = $this->eventDispatcher
      ->dispatch(Events::SESSION_CREATE, new SessionEvent($order));
    $build = $event->getRequest()->toArray();

    $session = $this->getSession($order);

    // Create new session if one doesn't exist already.
    if (!isset($session['session_id'])) {
      $session->create($build);
    }
    else {
      try {
        $session->update($build);
        $session->fetch();
      }
      catch (ConnectorException | ClientException $e) {
        // Attempt to create new session session if update failed.
        // This usually happens when we have an expired session id
        // saved in commerce order.
        $session->create($build);
      }
    }
    // Session id will be reset when we update the session and
    // re-fetch. Update only if session id has changed.
    if (!empty($session['session_id'])) {
      $order->setData('klarna_session_id', $session['session_id'])
        ->save();
    }
    return $build + [
      'client_token' => $session['client_token'],
      'payment_method_category' => reset($session['payment_method_categories']),
    ];
  }

}
