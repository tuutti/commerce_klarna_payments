<?php

namespace Drupal\Tests\commerce_klarna_payments\Unit;

use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_price\Price;
use Drupal\Tests\UnitTestCase;

/**
 * Unit converter unit tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Bridge\UnitConverter
 */
class UnitConverterTest extends UnitTestCase {

  /**
   * Tests UnitConverter::toAmount().
   *
   * @dataProvider toAmountData
   */
  public function testToAmount(Price $price, bool $positive, int $expected) {
    $this->assertEquals($expected, UnitConverter::toAmount($price, $positive));
  }

  /**
   * Data provider for ::testToAmount().
   *
   * @return array
   *   The data.
   */
  public function toAmountData() : array {
    return [
      // Positive numbers.
      [new Price('100', 'USD'), FALSE, 10000],
      [new Price('100.5', 'USD'), FALSE, 10050],
      [new Price('100.55', 'USD'), FALSE, 10055],
      [new Price('100.555', 'USD'), FALSE, 10055],
      [new Price('100.55999999', 'USD'), FALSE, 10055],
      // Positive numbers when we don't convert to positive number.
      [new Price('100', 'USD'), TRUE, 10000],
      [new Price('100.5', 'USD'), TRUE, 10050],
      [new Price('100.55', 'USD'), TRUE, 10055],
      [new Price('100.555', 'USD'), TRUE, 10055],
      [new Price('100.55999999', 'USD'), TRUE, 10055],
      // Negative numbers without converting to positive number.
      [new Price('-100', 'USD'), FALSE, -10000],
      [new Price('-100.5', 'USD'), FALSE, -10050],
      [new Price('-100.55', 'USD'), FALSE, -10055],
      [new Price('-100.555', 'USD'), FALSE, -10055],
      [new Price('-100.55999999', 'USD'), FALSE, -10055],
      // Negative numbers when we convert to positive.
      [new Price('-100', 'USD'), TRUE, 10000],
      [new Price('-100.5', 'USD'), TRUE, 10050],
      [new Price('-100.55', 'USD'), TRUE, 10055],
      [new Price('-100.555', 'USD'), TRUE, 10055],
      [new Price('-100.55999999', 'USD'), TRUE, 10055],
    ];
  }

  /**
   * Tests UnitConverter::toTaxRate().
   *
   * @dataProvider toTaxRateData
   */
  public function testToTaxRate(string $percentage, int $expected) {
    $this->assertEquals($expected, UnitConverter::toTaxRate($percentage));
  }

  /**
   * Data provider for ::testToTaxRate().
   *
   * @return array
   *   The data.
   */
  public function toTaxRateData() : array {
    return [
      ['0', 0],
      ['24', 240000],
      ['24.5', 245000],
      ['24.55', 245500],
      ['24.5555', 245555],
      ['24.55555555555', 245555],
    ];
  }

  /**
   * Tests UnitConverter::toPrice().
   *
   * @dataProvider toPriceData
   */
  public function testToPrice(int $amount, Price $expected) {
    $this->assertEquals($expected->getNumber(), UnitConverter::toPrice($amount, 'USD')->getNumber());
  }

  /**
   * Data provider for ::testToPrice().
   *
   * @return array
   *   The data.
   */
  public function toPriceData() : array {
    return [
      [10000, new Price('100', 'USD')],
      [10050, new Price('100.5', 'USD')],
      [10055, new Price('100.55', 'USD')],
    ];
  }

}
