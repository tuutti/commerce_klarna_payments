<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * A helper trait for Klarna payment gateway plugin.
 */
trait PaymentGatewayPluginTrait {

  /**
   * Gets the plugin for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface
   *   The payment plugin.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException|
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getPaymentGatewayPlugin(OrderInterface $order) : KlarnaInterface {
    $gateway = $order->get('payment_gateway');

    if ($gateway->isEmpty()) {
      throw new \InvalidArgumentException('Payment gateway not found.');
    }
    $plugin = $gateway->first()->entity->getPlugin();

    if (!$plugin instanceof KlarnaInterface) {
      throw new NonKlarnaOrderException('Payment gateway not instanceof Klarna.');
    }
    return $plugin;
  }

}
