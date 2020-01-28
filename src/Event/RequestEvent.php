<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Event to store request data.
 */
final class RequestEvent extends Event {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  private $order;

  /**
   * The session data.
   *
   * @var array
   */
  private $data = [];

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $data
   *   The data.
   */
  public function __construct(OrderInterface $order, array $data = []) {
    $this->order = clone $order;

    if ($data) {
      $this->data = $data;
    }
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
   * @param array $data
   *   The session data.
   *
   * @return $this
   *   The self.
   */
  public function setData(array $data) : self {
    $this->data = $data;
    return $this;
  }

  /**
   * Gets the session data.
   *
   * @return array
   *   The session/order data.
   */
  public function getData() : array {
    return $this->data;
  }

}
