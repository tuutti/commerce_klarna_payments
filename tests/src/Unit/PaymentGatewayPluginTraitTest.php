<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_klarna_payments\Unit;

use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\PaymentGatewayPluginTrait;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface as PaymentGatewayPluginInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * ApiManager tests.
 *
 * @group commerce_klarna_payments
 */
class PaymentGatewayPluginTraitTest extends UnitTestBase {

  use ProphecyTrait;

  /**
   * Tests getPlugin() when gateway is not set.
   *
   * @covers \Drupal\commerce_klarna_payments\PaymentGatewayTrait::getPlugin
   */
  public function testGetPluginGatewayNotFound() : void {
    $this->expectException(NonKlarnaOrderException::class);

    $list = $this->prophesize(FieldItemInterface::class);
    $list->isEmpty()->willReturn(TRUE);

    $order = $this->prophesize(OrderInterface::class);
    $order->get('payment_gateway')->willReturn($list->reveal());

    $sut = $this->getObjectForTrait(PaymentGatewayPluginTrait::class);
    $sut->getPlugin($order->reveal());
  }

  /**
   * Tests getPlugin() for non-klarna orders.
   *
   * @covers \Drupal\commerce_klarna_payments\PaymentGatewayTrait::getPlugin
   */
  public function testGetPluginNonKlarnaOrder() {
    $this->expectException(NonKlarnaOrderException::class);

    $plugin = $this->prophesize(PaymentGatewayPluginInterface::class);
    $gateway = $this->prophesize(PaymentGatewayInterface::class);
    $gateway->getPlugin()->willReturn($plugin->reveal());

    $list = $this->prophesize(FieldItemListInterface::class);
    $list->isEmpty()->willReturn(FALSE);
    $list->first()->willReturn((object) ['entity' => $gateway->reveal()]);

    $order = $this->prophesize(OrderInterface::class);
    $order->get('payment_gateway')->willReturn($list->reveal());

    $sut = $this->getObjectForTrait(PaymentGatewayPluginTrait::class);
    $sut->getPlugin($order->reveal());
  }

  /**
   * Tests getPlugin() with valid data.
   *
   * @covers ::getPlugin
   */
  public function testGetPlugin() : void {
    $order = $this->prophesize(OrderInterface::class);
    $this->populateOrderPluginStub($order);

    $sut = $this->getObjectForTrait(PaymentGatewayPluginTrait::class);
    $plugin = $sut->getPlugin($order->reveal());

    $this->assertInstanceOf(KlarnaInterface::class, $plugin);
  }

}
