<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_tax\Entity\TaxType;
use Drupal\profile\Entity\Profile;

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
  protected ?RequestBuilder $sut;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'address',
    'commerce_tax',
    'commerce_promotion',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();

    $this->installConfig(['commerce_promotion', 'commerce_tax', 'address']);
    $this->installEntitySchema('commerce_promotion');

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
      'locale' => 'en-FI',
      'merchant_urls' => (object) [
        'confirmation' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_payments',
        'notification' => 'http://localhost/payment/notify/klarna_payments?step=payment&commerce_order=1',
        'push' => 'http://localhost/commerce_klarna_payments/1/klarna_payments/push?step=payment&klarna_order_id={order.id}',
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
    // Tax registrations field is not populated by default.
    $this->store->set('tax_registrations', ['FI'])
      ->set('prices_include_tax', TRUE)->save();

    $order = $this->reloadEntity($this->createOrder());

    $request = $this->sut->createSessionRequest($order);

    $expected = [
      'purchase_country' => 'FI',
      'purchase_currency' => 'EUR',
      'locale' => 'en-FI',
      'merchant_urls' => (object) [
        'confirmation' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_payments',
        'notification' => 'http://localhost/payment/notify/klarna_payments?step=payment&commerce_order=1',
        'push' => 'http://localhost/commerce_klarna_payments/1/klarna_payments/push?step=payment&klarna_order_id={order.id}',
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
    // Tax registrations field is not populated by default.
    $this->store->set('tax_registrations', ['FI'])
      ->set('prices_include_tax', FALSE)->save();

    $order = $this->reloadEntity($this->createOrder());
    $request = $this->sut->createSessionRequest($order);

    $expected = [
      'purchase_country' => 'FI',
      'purchase_currency' => 'EUR',
      'locale' => 'en-FI',
      'merchant_urls' => (object) [
        'confirmation' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_payments',
        'notification' => 'http://localhost/payment/notify/klarna_payments?step=payment&commerce_order=1',
        'push' => 'http://localhost/commerce_klarna_payments/1/klarna_payments/push?step=payment&klarna_order_id={order.id}',
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
    $this->assertEquals(NULL, $request->getBillingAddress());

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
   * Make sure order level promotions are calculated correctly.
   */
  public function testOrderPromotion() : void {
    $order = $this->createOrder();
    $order->addAdjustment(new Adjustment(
      [
        'type' => 'promotion',
        'label' => 'D',
        'amount' => new Price('-1.1', 'EUR'),
        'percentage' => '10',
      ]
    ));
    $order->setRefreshState(OrderInterface::REFRESH_ON_LOAD);
    $order->save();

    $request = $this->sut->createSessionRequest($order);
    $this->assertEquals(990, $request->getOrderAmount());
  }

  /**
   * Tests order item promotions.
   */
  public function testOrderItemPromotion() : void {
    $order = $this->createOrder([
      new Adjustment(
        [
          'type' => 'custom',
          'label' => 'D',
          'amount' => new Price('-1.1', 'EUR'),
        ]
      ),
    ]);
    $order = $this->reloadEntity($order);

    $request = $this->sut->createSessionRequest($order);
    $this->assertEquals(990, $request->getOrderAmount());
    $this->assertEquals(990, $request->getOrderLines()[0]->getUnitPrice());
    $this->assertEquals(990, $request->getOrderLines()[0]->getTotalAmount());
  }

}
