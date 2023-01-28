<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Traits;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;

/**
 * Helper trait to create commerce orders.
 */
trait OrderTestTrait {

  /**
   * Creates new order.
   *
   * @param \Drupal\commerce_order\Adjustment[] $itemAdjustments
   *   The order item adjustments.
   * @param array $data
   *   The order data.
   * @param \Drupal\commerce_price\Price|null $price
   *   The unit price.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  protected function createOrder(array $itemAdjustments = [], array $data = [], Price $price = NULL): OrderInterface {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $orderItem */
    $orderItem = OrderItem::create([
      'type' => 'test',
    ]);

    if (!$price) {
      $price = new Price('11', 'EUR');
    }

    $orderItem->setUnitPrice($price)
      ->setTitle('Title');

    if ($itemAdjustments) {
      foreach ($itemAdjustments as $adjustment) {
        $orderItem->addAdjustment($adjustment);
      }
    }
    $orderItem->save();

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'store_id' => $this->store,
      'payment_gateway' => $this->gateway,
    ]);

    $order->addItem($orderItem)
      ->setRefreshState(OrderInterface::REFRESH_ON_LOAD);

    if ($data) {
      foreach ($data as $key => $value) {
        $order->setData($key, $value);
      }
    }
    $order->save();

    return $this->reloadEntity($order);
  }

}
