<?php

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\ObjectSerializerTrait;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Klarna\Model\ModelInterface;
use Klarna\ObjectSerializer;

/**
 * Kernel test base for Klarna payments.
 */
abstract class KlarnaKernelBase extends CommerceKernelTestBase {

  use ObjectSerializerTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'entity_reference_revisions',
    'profile',
    'path',
    'state_machine',
    'commerce_product',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_payment',
    'commerce_checkout',
    'commerce_klarna_payments',
  ];

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGateway
   */
  protected $gateway;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installConfig(['commerce_product', 'commerce_order']);
    $this->installConfig('commerce_payment');
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);

    $this->gateway = PaymentGateway::create([
      'id' => 'klarna_payments',
      'label' => 'Klarna',
      'plugin' => 'klarna_payments',
    ]);
    $this->gateway->save();

    $this->store = $this->createStore('Default store', 'admin@example.com', 'online', TRUE, 'FI', 'EUR');
  }

  /**
   * Creates new order.
   *
   * @param \Drupal\commerce_order\Adjustment[] $itemAdjustments
   *   The order item adjustments.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  protected function createOrder(array $itemAdjustments = []): OrderInterface {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $orderItem */
    $orderItem = OrderItem::create([
      'type' => 'default',
    ]);
    $orderItem->setUnitPrice(new Price('11', 'EUR'))
      ->setTitle('Title');

    if ($itemAdjustments) {
      foreach ($itemAdjustments as $adjustment) {
        $orderItem->addAdjustment($adjustment);
      }
    }
    $orderItem->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store,
      'payment_gateway' => $this->gateway,
    ]);
    $order->addItem($orderItem);
    $order->save();

    return $order;
  }

}
