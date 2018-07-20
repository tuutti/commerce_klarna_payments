<?php

namespace Drupal\Tests\commerce_klarna_payments\Unit;

use Drupal\commerce_klarna_payments\Klarna\Request\Order\CaptureRequest;
use Drupal\Tests\UnitTestCase;

/**
 * Capture request unit tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Klarna\Request\Order\CaptureRequest
 */
class CaptureRequestTest extends UnitTestCase {

  /**
   * Tests toArray() method.
   *
   * @covers ::toArray
   * @covers ::setCapturedAmount
   * @covers ::setDescription
   * @covers ::setShippingDelay
   */
  public function testToArray() {
    $request = new CaptureRequest();

    $this->assertEquals(['auto_capture' => TRUE], $request->toArray());
  }

}
