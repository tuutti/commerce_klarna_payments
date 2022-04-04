<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Provides a trait to interact with payment plugin.
 */
trait PaymentPluginTrait {

  /**
   * Gets the plugin for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   *   The payment plugin.
   *
   * @throws \Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException
   */
  public function getPaymentPlugin(OrderInterface $order) : KlarnaInterface {
    $gateway = $order->get('payment_gateway');

    if ($gateway->isEmpty()) {
      throw new NonKlarnaOrderException('Payment gateway not found.');
    }
    $plugin = $gateway->first()->entity->getPlugin();

    if (!$plugin instanceof KlarnaInterface) {
      throw new NonKlarnaOrderException('Payment plugin is not Klarna.');
    }
    return $plugin;
  }

}
