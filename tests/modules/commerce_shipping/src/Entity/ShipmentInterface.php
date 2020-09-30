<?php

declare(strict_types = 1);

namespace Drupal\commerce_shipping\Entity;

use Drupal\commerce_price\Price;

/**
 * Fake shipment interface.
 */
interface ShipmentInterface {

  public function getAmount() : Price;

  public function getAdjustments(array $types) : array;

}
