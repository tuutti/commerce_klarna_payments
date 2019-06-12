<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use KlarnaPayments\Data\Payment\Session;
use Symfony\Component\EventDispatcher\Event;

/**
 * Event to store session data.
 */
final class RequestEvent extends Event {

  protected $order;
  protected $object;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  public function __construct(OrderInterface $order) {
    $this->order = clone $order;
  }

  /**
   * Gets the order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  public function getOrder() : OrderInterface {
    return $this->order;
  }

  /**
   * Sets the session data.
   *
   * @param \KlarnaPayments\Data\Payment\Session|\KlarnaPayments\Data\Order\Order $session
   *   The session data.
   *
   * @return $this
   *   The self.
   */
  public function setObject(Session $session) : self {
    $this->object = $session;
    return $this;
  }

  /**
   * Gets the request.
   *
   * @return \KlarnaPayments\Data\Payment\Session|\KlarnaPayments\Data\Order\Order
   *   The session data.
   */
  public function getObject() : ? Session {
    return $this->object;
  }

}
