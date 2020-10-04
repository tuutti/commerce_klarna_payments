<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_tax\Entity\TaxType;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\profile\Entity\Profile;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Request builder tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
 */
class RequestBuilderTest extends KlarnaKernelBase {

  /**
   * The request builder.
   *
   * @var \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
   */
  protected $sut;

  /**
   * {@inheritdoc}
   */
  public static $modules = ['address', 'commerce_tax', 'commerce_shipping'];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installConfig('address');
    $this->installConfig('commerce_tax');

    $this->sut = $this->container->get('commerce_klarna_payments.request_builder');
  }

  /**
   * Create tax types.
   */
  protected function createTaxType() : void {
    TaxType::create([
      'id' => 'vat',
      'label' => 'VAT',
      'plugin' => 'european_union_vat',
      'configuration' => [
        'display_inclusive' => TRUE,
      ],
      'status' => TRUE,
    ])->save();
  }

  /**
   * Tests ::createSessionRequest().
   */
  public function testCreateSessionRequestNoTax() : void {
    $request = $this->sut->createSessionRequest($this->createOrder());

    $expected = [
      'purchase_country' => 'FI',
      'purchase_currency' => 'EUR',
      'locale' => 'en-US',
      'merchant_urls' => (object) [
        'confirmation' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_payments',
        'notification' => 'http://localhost/payment/notify/klarna_payments?step=payment&commerce_order=1',
      ],
      'order_amount' => 1100,
      'order_lines' => [
        (object) [
          'name' => 'Title',
          'quantity' => 1,
          'unit_price' => 1100,
          'total_amount' => 1100,
        ],
      ],
      'order_tax_amount' => 0,
    ];
    $this->assertEquals($expected, $this->modelToArray($request));
  }

  /**
   * Make sure taxes are included in prices.
   */
  public function testCreateSessionRequestTaxIncludedInPrices() : void {
    $this->createTaxType();
    $this->store->set('prices_include_tax', TRUE)->save();

    $request = $this->sut->createSessionRequest($this->createOrder());

    $expected = [
      'purchase_country' => 'FI',
      'purchase_currency' => 'EUR',
      'locale' => 'en-US',
      'merchant_urls' => (object) [
        'confirmation' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_payments',
        'notification' => 'http://localhost/payment/notify/klarna_payments?step=payment&commerce_order=1',
      ],
      'order_amount' => 1100,
      'order_lines' => [
        (object) [
          'name' => 'Title',
          'quantity' => 1,
          'unit_price' => 1100,
          'total_amount' => 1100,
          'tax_rate' => 2400,
          'total_tax_amount' => 213,
        ],
      ],
      'order_tax_amount' => 213,
    ];
    $this->assertEquals($expected, $this->modelToArray($request));
  }

  /**
   * Make sure we collect taxes even when they are not included in prices.
   */
  public function testCreateSessionRequestTaxNotIncludedInPrices() : void {
    $this->createTaxType();
    $this->store->set('prices_include_tax', FALSE)->save();

    $request = $this->sut->createSessionRequest($this->createOrder());

    $expected = [
      'purchase_country' => 'FI',
      'purchase_currency' => 'EUR',
      'locale' => 'en-US',
      'merchant_urls' => (object) [
        'confirmation' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_payments',
        'notification' => 'http://localhost/payment/notify/klarna_payments?step=payment&commerce_order=1',
      ],
      'order_amount' => 1364,
      'order_lines' => [
        (object) [
          'name' => 'Title',
          'quantity' => 1,
          'unit_price' => 1364,
          'total_amount' => 1364,
          'tax_rate' => 2400,
          'total_tax_amount' => 264,
        ],
      ],
      'order_tax_amount' => 264,
    ];
    $this->assertEquals($expected, $this->modelToArray($request));
  }

  /**
   * Tests that options are set correctly.
   */
  public function testOptions() : void {
    $options = [
      'color_border' => '#FFFFFF',
      'color_border_selected' => '#FFFFFF',
      'color_details' => '#FFFFFF',
      'color_text' => '#FFFFFF',
      'radius_border' => '5px',
    ];
    $request = $this->sut->createSessionRequest($this->createOrder());
    $this->assertEquals(NULL, $request->getOptions());

    $this->gateway->setPluginConfiguration(['options' => $options])->save();

    $request = $this->sut->createSessionRequest($this->createOrder());
    $this->assertEquals($options, $this->modelToArray($request->getOptions()));
  }

  /**
   * Tests ::createCaptureRequest().
   */
  public function testCreateCaptureRequest() : void {
    $expected = [
      'captured_amount' => 1100,
      'order_lines' => [
        (object) [
          'name' => 'Title',
          'quantity' => 1,
          'unit_price' => 1100,
          'total_amount' => 1100,
        ],
      ],
    ];

    $request = $this->sut->createCaptureRequest($this->createOrder());

    $this->assertEquals($expected, $this->modelToArray($request));
  }

  /**
   * Tests that billing profile is set.
   */
  public function testBillingAddress() : void {
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'FI',
        'locality' => 'Järvenpää',
        'postal_code' => '04400',
        'address_line1' => 'Mannilantie 1',
        'given_name' => 'Jorma',
        'family_name' => 'Testaaja',
      ],
    ]);
    $profile->save();

    $order = $this->createOrder();

    $request = $this->sut->createSessionRequest($order);
    $this->assertEqual(NULL, $request->getBillingAddress());

    $order
      ->setEmail('test@example.com')
      ->setBillingProfile($profile)
      ->save();

    $expected = [
      'email' => 'test@example.com',
      'family_name' => 'Testaaja',
      'given_name' => 'Jorma',
      'city' => 'Järvenpää',
      'country' => 'FI',
      'postal_code' => '04400',
      'street_address' => 'Mannilantie 1',
    ];

    $request = $this->sut->createSessionRequest($order);

    $this->assertEquals($expected, $this->modelToArray($request->getBillingAddress()));
  }

  /**
   * Creates object mock to test shipments.
   *
   * @param array $adjustments
   *   The adjustments.
   *
   * @return \Prophecy\Prophecy\ObjectProphecy
   *   The mock.
   */
  private function createOrderMock(array $adjustments = []) : ObjectProphecy {
    $shipments = $this->prophesize(ShipmentInterface::class);
    $shipments->getAmount()
      ->willReturn(new Price('9', 'EUR'));
    $shipments->getAdjustments(['tax'])
      ->willReturn($adjustments);

    $shipmentsList = $this->prophesize(EntityReferenceFieldItemListInterface::class);
    $shipmentsList->isEmpty()
      ->willReturn(FALSE);
    $shipmentsList->referencedEntities()
      ->willReturn([$shipments->reveal()]);

    $purchasedEntity = $this->prophesize(ProductVariationInterface::class);
    $purchasedEntity->getEntityTypeId()
      ->willReturn('commerce_product_variation');
    $purchasedEntity->id()
      ->willReturn('1');

    $orderItem = $this->prophesize(OrderItemInterface::class);
    $orderItem->getPurchasedEntity()
      ->willReturn($purchasedEntity);
    $orderItem->getTitle()
      ->willReturn('Title');
    $orderItem->getQuantity()
      ->willReturn(1);
    $orderItem->getAdjustedUnitPrice()
      ->willReturn(new Price('11', 'EUR'));
    $orderItem->getTotalPrice()
      ->willReturn(new Price('11', 'EUR'));
    $orderItem->getAdjustments(['tax'])
      ->willReturn([]);

    $order = $this->prophesize(OrderInterface::class);
    $order->payment_gateway = (object) ['entity' => $this->gateway];
    $order->id()->willReturn('1');
    $order->getStore()->willReturn($this->store);
    $order->getTotalPrice()->willReturn(new Price('11', 'EUR'));
    $order->collectProfiles()->willReturn([]);
    $order->getItems()->willReturn([$orderItem->reveal()]);
    $order->hasField('shipments')->willReturn(TRUE);
    $order->get('shipments')->willReturn($shipmentsList->reveal());

    return $order;
  }

  /**
   * Make sure we can collect shipping payments with taxes.
   *
   * @covers ::createSessionRequest
   */
  public function testShipmentTaxes() : void {
    $order_lines = [
      [
        'name' => 'Title',
        'quantity' => 1,
        'unit_price' => 1100,
        'total_amount' => 1100,
        'reference' => 'commerce_product_variation:1',
      ],
      [
        'name' => 'Shipping',
        'quantity' => 1,
        'unit_price' => 900,
        'total_amount' => 900,
        'type' => 'shipping_fee',
        'tax_rate' => 2400,
        'total_tax_amount' => 171,
      ],
    ];

    $order = $this->createOrderMock([
      new Adjustment([
        'type' => 'tax',
        'label' => 'Tax',
        'amount' => new Price('1.71', 'EUR'),
        'percentage' => '0.24',
      ]),
    ]);
    $request = $this->sut->createSessionRequest($order->reveal());
    $this->assertEquals($order_lines, iterator_to_array($this->modelsToArray($request->getOrderLines())));
    $this->assertEquals(171, $request->getOrderTaxAmount());
    $this->assertEquals(1100, $request->getOrderAmount());
  }

  /**
   * Make sure we can collect shipping payments.
   *
   * @covers ::createSessionRequest
   */
  public function testShipmentNoTaxes() : void {
    $order_lines = [
      [
        'name' => 'Title',
        'quantity' => 1,
        'unit_price' => 1100,
        'total_amount' => 1100,
        'reference' => 'commerce_product_variation:1',
      ],
      [
        'name' => 'Shipping',
        'quantity' => 1,
        'unit_price' => 900,
        'total_amount' => 900,
        'type' => 'shipping_fee',
      ],
    ];
    $order = $this->createOrderMock();
    $request = $this->sut->createSessionRequest($order->reveal());

    $this->assertEquals($order_lines, iterator_to_array($this->modelsToArray($request->getOrderLines())));
    $this->assertEquals(0, $request->getOrderTaxAmount());
    $this->assertEquals(1100, $request->getOrderAmount());
  }

  /**
   * Tests the locale.
   */
  public function testLocale() : void {
    $order = $this->createOrder();

    $tests = [
      'fi' => 'fi-FI',
      'at' => 'de-AT',
      'nonexistent' => 'en-US',
    ];

    foreach ($tests as $langcode => $expected) {
      $language = $this->prophesize(LanguageInterface::class);
      $language->getId()->willReturn($langcode);
      $languageManager = $this->prophesize(LanguageManagerInterface::class);
      $languageManager->getCurrentLanguage()->willReturn($language->reveal());

      $sut = new RequestBuilder($languageManager->reveal());
      $request = $sut->createSessionRequest($order);

      $this->assertEquals($expected, $request->getLocale());
    }
  }

}
