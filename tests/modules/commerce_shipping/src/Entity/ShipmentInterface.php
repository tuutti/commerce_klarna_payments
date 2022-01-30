<?php

declare(strict_types = 1);

namespace Drupal\commerce_shipping\Entity;

use Drupal\commerce_price\Price;

/**
 * Fake shipment interface.
 */
interface ShipmentInterface {

  /**
   * Gets the amount.
   *
   * @return \Drupal\commerce_price\Price
   *   The amount.
   */
  public function getAmount() : Price;

  /**
   * Gets the adjustments.
   *
   * @param array $types
   *   The types.
   *
   * @return array
   *   The adjustments.
   */
  public function getAdjustments(array $types) : array;

}
