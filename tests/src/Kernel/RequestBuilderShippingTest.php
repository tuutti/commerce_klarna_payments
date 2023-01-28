<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\commerce_tax\Entity\TaxType;
use Drupal\physical\Weight;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_klarna_payments\Traits\TaxTestTrait;
use Drupal\Tests\commerce_shipping\Kernel\ShippingKernelTestBase;

/**
 * Request builder tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
 */
class RequestBuilderShippingTest extends ShippingKernelTestBase {

  use TaxTestTrait;

  /**
   * The request builder.
   *
   * @var null|\Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
   */
  protected ?RequestBuilder $sut;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_tax',
    'commerce_checkout',
    'commerce_payment',
    'commerce_promotion',
    'commerce_klarna_payments',
  ];

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGateway
   */
  protected ?PaymentGateway $gateway;

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();

    $this->installConfig(['commerce_checkout']);
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_promotion');

    $this->setupTaxes();
    $this->store = $this->createStore(country: 'FI', currency: 'EUR');
    $this->gateway = PaymentGateway::create([
      'id' => 'klarna_payments',
      'label' => 'Klarna',
      'plugin' => 'klarna_payments',
    ]);
    $this->gateway->save();

    $this->sut = $this->container->get('commerce_klarna_payments.request_builder');
  }

  /**
   * Setup order object.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  private function createShippingOrder() : OrderInterface {
    TaxType::create([
      'id' => 'shipping',
      'label' => 'Shipping',
      'plugin' => 'shipping',
      'configuration' => [
        'strategy' => 'default',
      ],
    ])->save();

    $shipping_method = ShippingMethod::create([
      'stores' => $this->store->id(),
      'name' => 'Standard shipping',
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [
          'rate_label' => 'Standard shipping',
          'rate_amount' => [
            'number' => '10.00',
            'currency_code' => 'EUR',
          ],
        ],
      ],
      'status' => TRUE,
    ]);
    $shipping_method->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'test-product-01',
      'title' => 'Hat',
      'price' => new Price('11', 'EUR'),
      'weight' => new Weight('0', 'g'),
    ]);
    $variation->save();

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $orderItem */
    $orderItem = OrderItem::create([
      'quantity' => 2,
      'type' => 'default',
      'purchased_entity' => $variation,
    ]);
    $orderItem->setUnitPrice(new Price('11', 'EUR'))
      ->setTitle($variation->getOrderItemTitle());

    $orderItem->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store,
      'uid' => $this->createUser(['mail' => 'admin@example.com']),
      'payment_gateway' => $this->gateway,
      'mail' => 'admin@example.com',
    ]);
    $order->addItem($orderItem);
    $order->save();

    /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
    $shipping_profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'FI',
      ],
    ]);
    $shipping_profile->save();

    $shipping_order_manager = $this->container->get('commerce_shipping.order_manager');
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $shipping_order_manager->pack($order, $shipping_profile);
    $shipment = reset($shipments);
    $shipment->setShippingMethodId($shipping_method->id());
    $shipment->setShippingService('default');
    $shipment->setAmount(new Price('10.00', 'EUR'));
    $order->set('shipments', [$shipment]);
    $order->save();
    return $order;
  }

  /**
   * Tests shipment without taxes.
   */
  public function testShipmentWithoutTaxes() : void {
    $order = $this
      ->setPricesIncludeTax(FALSE)
      ->createShippingOrder();
    $items = $this->sut->createSessionRequest($order)->getOrderLines();

    /** @var \Klarna\Payments\Model\OrderLine $shippingItem */
    $shippingItem = end($items);
    static::assertEquals(0, $shippingItem->getTaxRate());
    static::assertEquals(1000, $shippingItem->getUnitPrice());
    static::assertEquals('shipping_fee', $shippingItem->getType());
  }

  /**
   * Tests shipment with taxes included.
   */
  public function testShipmentIncludeTaxes() : void {
    $order = $this
      ->setPricesIncludeTax(TRUE, ['FI'])
      ->createShippingOrder();
    $items = $this->sut->createSessionRequest($order)->getOrderLines();

    /** @var \Klarna\Payments\Model\OrderLine $shippingItem */
    $shippingItem = end($items);
    static::assertEquals(2400, $shippingItem->getTaxRate());
    static::assertEquals(1000, $shippingItem->getUnitPrice());
    static::assertEquals('shipping_fee', $shippingItem->getType());
  }

  /**
   * Tests taxes when taxes are not included in prices.
   */
  public function testShipmentIncludeNoTaxes() : void {
    $order = $this
      ->setPricesIncludeTax(FALSE, ['FI'])
      ->createShippingOrder();
    $items = $this->sut->createSessionRequest($order)->getOrderLines();

    /** @var \Klarna\Payments\Model\OrderLine $shippingItem */
    $shippingItem = end($items);
    static::assertEquals(2400, $shippingItem->getTaxRate());
    // @todo commerce_shipping doesn't respect store's tax setting at the moment.
    // Fix the unit price if this is ever fixed.
    // @see https://www.drupal.org/project/commerce_shipping/issues/3189727
    static::assertEquals(1000, $shippingItem->getUnitPrice());
    static::assertEquals('shipping_fee', $shippingItem->getType());
  }

}
