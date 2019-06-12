<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Bridge;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use KlarnaPayments\Data\Amount;

/**
 * Bridge between KlarnaPayment and commerces various units.
 *
 * - \KlarnaPayments\Data\Amount <-> \Drupal\commerce_price\Price.
 * - Tax rate <-> Tax percentage.
 */
final class UnitBridge {

  /**
   * Converts commerce price to Klarna "amount".
   *
   * @param \Drupal\commerce_price\Price $price
   *   The price to convert.
   *
   * @return \KlarnaPayments\Data\Amount
   *   The amount.
   */
  public static function toAmount(Price $price) : Amount {
    $value = (int) $price->multiply('100')->getNumber();

    return new Amount($value, $price->getCurrencyCode());
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
   * @param \KlarnaPayments\Data\Amount $amount
   *   The klarna amount.
   *
   * @return \Drupal\commerce_price\Price
   *   The commerce price.
   */
  public static function toPrice(Amount $amount) : Price {
    // Divide Klarna price by 100 to get commerce compatible
    // price.
    $value = Calculator::divide($amount->getNumber(), '100');

    return new Price($value, $amount->getCurrency());
  }

}
