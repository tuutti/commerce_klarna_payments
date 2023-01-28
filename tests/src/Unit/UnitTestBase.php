<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Unit;

use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Klarna\Configuration;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Base class for Klarna unit tests.
 */
abstract class UnitTestBase extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Gets the fixture.
   *
   * @param string $name
   *   The name of the fixture.
   *
   * @return string
   *   The fixture.
   */
  protected function getFixture(string $name) : string {
    return file_get_contents(__DIR__ . '/../../fixtures/' . $name);
  }

  /**
   * Populates working plugin to the given order.
   *
   * @param \Prophecy\Prophecy\ObjectProphecy $order
   *   The order to populate the stub to.
   */
  protected function populateOrderPluginStub(ObjectProphecy $order) : void {
    $plugin = $this->prophesize(KlarnaInterface::class);
    $plugin->getClientConfiguration()->willReturn(new Configuration());

    $gateway = $this->prophesize(PaymentGatewayInterface::class);
    $gateway->getPlugin()->willReturn($plugin->reveal());

    $list = $this->prophesize(FieldItemListInterface::class);
    $list->isEmpty()->willReturn(FALSE);
    $list->first()->willReturn((object) ['entity' => $gateway->reveal()]);

    $order->get('payment_gateway')->willReturn($list->reveal());
    $order->uuid()->willReturn('123');
  }

}
