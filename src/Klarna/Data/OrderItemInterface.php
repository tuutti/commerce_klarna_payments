<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Data;

/**
 * An interface to describe order items.
 */
interface OrderItemInterface extends ObjectInterface {

  /**
   * Sets the type.
   *
   * @param string $type
   *   The type.
   *
   * @return $this
   *   The self.
   */
  public function setType(string $type) : OrderItemInterface;

  /**
   * Sets the name.
   *
   * @param string $name
   *   The name.
   *
   * @return $this
   *   The self.
   */
  public function setName(string $name) : OrderItemInterface;

  /**
   * Sets the product URL.
   *
   * @param string $url
   *   The url.
   *
   * @return $this
   *   The self.
   */
  public function setProductUrl(string $url) : OrderItemInterface;

  /**
   * Sets the image URL.
   *
   * @param string $url
   *   The url.
   *
   * @return $this
   *   The self.
   */
  public function setImageUrl(string $url) : OrderItemInterface;

  /**
   * Sets the quantity.
   *
   * @param int $quantity
   *   The quantity.
   *
   * @return $this
   *   The self.
   */
  public function setQuantity(int $quantity) : OrderItemInterface;

  /**
   * Sets the quantity unit (like pcs, kg).
   *
   * @param string $unit
   *   The unit.
   *
   * @return $this
   *   The self.
   */
  public function setQuantityUnit(string $unit) : OrderItemInterface;

  /**
   * Sets he unit price (integer, 1000 = 10 €).
   *
   * Includes taxes, excludes discount.
   *
   * @param int $price
   *   The price.
   *
   * @return $this
   *   The self.
   */
  public function setUnitPrice(int $price) : OrderItemInterface;

  /**
   * Sets the tax rate (1000 = 10%).
   *
   * @param int $rate
   *   The tax rate.
   *
   * @return $this
   *   The self.
   */
  public function setTaxRate(int $rate) : OrderItemInterface;

  /**
   * Sets the total tax amount.
   *
   * @param int $amount
   *   The total tax amount.
   *
   * @return $this
   *   The self.
   */
  public function setTotalTaxAmount(int $amount) : OrderItemInterface;

  /**
   * Sets the total amount.
   *
   * Includes taxes and discount (quantity * unit_price).
   *
   * @param int $amount
   *   The total amount.
   *
   * @return $this
   *   The self.
   */
  public function setTotalAmount(int $amount) : OrderItemInterface;

  /**
   * Sets the reference (SKU or similar).
   *
   * @param string $reference
   *   The reference.
   *
   * @return $this
   *   The self.
   */
  public function setReference(string $reference) : OrderItemInterface;

}
