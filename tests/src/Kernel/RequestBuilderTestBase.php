<?php

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_price\Price;

/**
 * Request builder test base.
 */
abstract class RequestBuilderTestBase extends KlarnaKernelBase {

  /**
   * The request builder.
   *
   * @var \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
   */
  protected $sut;

  /**
   * A sample user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // An order item type that doesn't need a purchasable entity, for
    // simplicity.
    OrderItemType::create([
      'id' => 'test',
      'label' => 'Test',
      'orderType' => 'default',
    ])->save();

    $user = $this->createUser();
    $this->user = $this->reloadEntity($user);

    $this->sut = $this->container->get('commerce_klarna_payments.request_builder');
  }

  /**
   * Create dummy order.
   *
   * @param \Drupal\commerce_order\Adjustment[] $adjustments
   *   The adjustments.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  protected function createOrder(array $adjustments = []) : OrderInterface {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = OrderItem::create([
      'title' => 'Test',
      'type' => 'test',
      'quantity' => '1',
      'unit_price' => new Price('2.00', 'USD'),
    ]);

    foreach ($adjustments as $adjustment) {
      $order_item->addAdjustment($adjustment);
    }
    $order_item->save();
    $order_item = $this->reloadEntity($order_item);
    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'payment_gateway' => $this->gateway->id(),
    ]);
    $order->setStore($this->store);
    $order->addItem($order_item);
    $order->save();

    return $order;
  }

}
