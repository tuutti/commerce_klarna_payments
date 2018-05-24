<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Request;

use Drupal\commerce_klarna_payments\Klarna\Data\OrderItemInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\OrderItemTypeInterface;
use Drupal\commerce_klarna_payments\Klarna\ObjectNormalizer;
use Webmozart\Assert\Assert;

/**
 * Value object for order items.
 */
class OrderItem implements OrderItemInterface {

  use ObjectNormalizer;

  protected $data = [];

  /**
   * {@inheritdoc}
   */
  public function setType(string $type) : OrderItemInterface {
    $interface = new \ReflectionClass(OrderItemTypeInterface::class);
    Assert::oneOf($type, $interface->getConstants());

    $this->data['type'] = $type;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setName(string $name) : OrderItemInterface {
    $this->data['name'] = $name;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setProductUrl(string $url) : OrderItemInterface {
    $this->data['product_url'] = $url;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setImageUrl(string $url) : OrderItemInterface {
    $this->data['image_url'] = $url;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setQuantity(int $quantity) : OrderItemInterface {
    $this->data['quantity'] = $quantity;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setQuantityUnit(string $unit) : OrderItemInterface {
    $this->data['quantity_unit'] = $unit;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setUnitPrice(int $price) : OrderItemInterface {
    $this->data['unit_price'] = $price;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTaxRate(int $rate) : OrderItemInterface {
    $this->data['tax_rate'] = $rate;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTotalTaxAmount(int $amount) : OrderItemInterface {
    $this->data['total_tax_amount'] = $amount;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTotalAmount(int $amount) : OrderItemInterface {
    $this->data['total_amount'] = $amount;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setReference(string $reference) : OrderItemInterface {
    $this->data['reference'] = $reference;
    return $this;
  }

}
