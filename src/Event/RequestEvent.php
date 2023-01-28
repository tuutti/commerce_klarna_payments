<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\EventDispatcher\Event;
use Klarna\Model\ModelInterface;

/**
 * Event to store request data.
 */
final class RequestEvent extends Event {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  private OrderInterface $order;

  /**
   * The model data.
   *
   * @var \Klarna\Model\ModelInterface
   */
  private ModelInterface $data;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Klarna\Model\ModelInterface|null $data
   *   The data.
   */
  public function __construct(OrderInterface $order, ModelInterface $data = NULL) {
    $this->order = clone $order;

    if ($data) {
      $this->setData($data);
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
   * @param \Klarna\Model\ModelInterface $data
   *   The session data.
   *
   * @return $this
   *   The self.
   */
  public function setData(ModelInterface $data) : self {
    $this->data = $data;
    return $this;
  }

  /**
   * Gets the session data.
   *
   * @return \Klarna\Model\ModelInterface|null
   *   The session/order data.
   */
  public function getData() : ? ModelInterface {
    return $this->data;
  }

}
