<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use KlarnaPayments\Request\RequestBase;
use Symfony\Component\EventDispatcher\Event;

/**
 * Event to store session data.
 */
final class RequestEvent extends Event {

  protected $order;
  protected $request;

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
   * Gets the request.
   *
   * @return \KlarnaPayments\Request\RequestBase|null
   *   The klarna request.
   */
  public function getRequest() : ? RequestBase {
    return $this->request;
  }

  /**
   * Sets the data.
   *
   * @param \KlarnaPayments\Request\RequestBase $request
   *   The request.
   *
   * @return $this
   *   The self.
   */
  public function setRequest(RequestBase $request) : self {
    $this->request = $request;
    return $this;
  }

}
