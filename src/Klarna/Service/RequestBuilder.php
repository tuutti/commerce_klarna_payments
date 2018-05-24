<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Service;

use Drupal\commerce_klarna_payments\Klarna\Data\AddressInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\RequestInterface;
use Drupal\commerce_klarna_payments\Klarna\Request\MerchantUrlset;
use Drupal\commerce_klarna_payments\Klarna\Request\OrderItem;
use Drupal\commerce_klarna_payments\Klarna\Request\Request;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides a request builder.
 */
class RequestBuilder {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * The payment gateway plugin.
   *
   * @var \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   */
  protected $plugin;

  /**
   * Constructs a new instance.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   */
  public function __construct(EventDispatcherInterface $eventDispatcher) {
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * Gets the payment plugin for order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   *   The payment plugin.
   */
  protected function getPaymentPlugin(OrderInterface $order) : Klarna {
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $entity */
    $entity = $order->payment_gateway->entity;

    return $entity->getPlugin();
  }

  /**
   * Populates the order and plugin.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return $this
   *   The self.
   */
  public function setOrder(OrderInterface $order) : self {
    $this->order = $order;
    $this->plugin = $this->getPaymentPlugin($order);

    return $this;
  }

  /**
   * Constructs a new instance with order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return $this
   *   The self.
   */
  public function withOrder(OrderInterface $order) : self {
    $instance = clone $this;
    $instance->setOrder($order);

    return $instance;
  }

  /**
   * Populates request object for given type.
   *
   * @param string $type
   *   The request type.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\RequestInterface
   *   The request.
   */
  public function generateRequest(string $type) : RequestInterface {
    switch ($type) {
      case 'create':
      case 'update':
        return $this->createUpdateRequest();

      case 'place':
        return $this->createPlaceRequest();
    }
    throw new \LogicException('Invalid type.');
  }

  /**
   * Creates update/create request object.
   *
   * @todo Figure out how to deal with minor units.
   * @todo Support shipping fees.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\RequestInterface
   *   The request.
   */
  protected function createUpdateRequest() : RequestInterface {
    $totalPrice = $this->order->getTotalPrice();

    $request = (new Request())
      ->setPurchaseCountry($this->getStoreLanguage())
      ->setPurchaseCurrency($totalPrice->getCurrencyCode())
      ->setLocale($this->plugin->getLocale())
      ->setBillingAddress($this->getBillingAddress())
      ->setOrderAmount((int) $totalPrice->multiply('100')->getNumber())
      ->setMerchantUrls(
        new MerchantUrlset([
          'confirmation' => $this->getReturnUri('commerce_payment.checkout.return'),
          'notify' => $this->getReturnUri('commerce_payment.notify', ['step' => 'complete']),
          'push' => 'test',
        ])
      )
      ->setOptions(NULL);

    $totalTax = 0;

    foreach ($this->order->getItems() as $item) {

      $orderItem = (new OrderItem())
        ->setName($item->getTitle())
        ->setQuantity((int) $item->getQuantity())
        ->setUnitPrice((int) $item->getUnitPrice()->multiply('100')->getNumber());

      foreach ($item->getAdjustments() as $adjustment) {
        // Only tax adjustments are supported by default.
        if ($adjustment->getType() !== 'tax') {
          continue;
        }
        // Calculate order's total tax.
        $totalTax += (int) $adjustment->getAmount()->multiply('100')->getNumber();
        // Multiply tax rate to have two implicit decimals, 2500 = 25%.
        $orderItem->setTaxRate((int) Calculator::multiply($adjustment->getPercentage(), '10000'));
      }
      $request->addOrderItem($orderItem);
    }
    $request->setOrderTaxAmount($totalTax);

    return $request;
  }

  protected function getBillingAddress() : AddressInterface {
  }

  protected function getReturnUri(string $routeName, array $arguments = []) : string {
    $arguments = array_merge($arguments, [
      'step' => 'payment',
      'commerce_order' => $this->order->id(),
      'commerce_payment_gateway' => $this->plugin->getPluginId(),
    ]);

    return (new Url($routeName, $arguments, ['absolute' => TRUE]))
      ->toString();
  }

  /**
   * Gets the store language.
   *
   * @return string
   *   The language.
   */
  protected function getStoreLanguage() : string {
    return $this->order->getStore()->getAddress()->getCountryCode();
  }

  /**
   * Getnerates place order request object.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\RequestInterface
   *   The request.
   */
  protected function createPlaceRequest() : RequestInterface {
    return new Request();
  }

}
