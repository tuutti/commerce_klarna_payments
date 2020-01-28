<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Bridge;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;

/**
 * Bridge between KlarnaPayment and commerces various units.
 *
 * - Amount(int) <-> \Drupal\commerce_price\Price.
 * - Tax rate(int) <-> Commerce tax percentage.
 */
final class UnitConverter {

  /**
   * Converts commerce price to Klarna "amount".
   *
   * @param \Drupal\commerce_price\Price $price
   *   The price to convert.
   *
   * @return int
   *   The amount.
   */
  public static function toAmount(Price $price) : int {
    $value = (int) $price->multiply('100')->getNumber();

    return $value;
  }

  /**
   * Converts tax rate to Klarna compatible rate.
   *
   * @param string $percentage
   *   The tax percentage.
   *
   * @return int
   *   The klarna compatible tax rate.
   */
  public static function toTaxRate(string $percentage) : int {
    return (int) Calculator::multiply($percentage, '10000');
  }

  /**
   * Converts Klarna "amount" to commerce price.
   *
   * @param int $amount
   *   The klarna amount.
   * @param string $currency
   *   The currency.
   *
   * @return \Drupal\commerce_price\Price
   *   The commerce price.
   */
  public static function toPrice(int $amount, string $currency) : Price {
    // Divide Klarna price by 100 to get commerce compatible
    // price.
    $value = Calculator::divide((string) $amount, '100');

    return new Price($value, $currency);
  }

}
