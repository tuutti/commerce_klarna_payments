<?php

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_price\Price;
use Drupal\commerce_tax\Entity\TaxType;

/**
 * Request builder tests.
 *
 * @group commerce_klarna_paymnents
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
 */
class RequestBuilderTaxTest extends RequestBuilderTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_tax',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installConfig('commerce_tax');

    TaxType::create([
      'id' => 'vat',
      'label' => 'VAT',
      'plugin' => 'european_union_vat',
      'configuration' => [
        'display_inclusive' => TRUE,
      ],
    ])->save();

    $this->store->set('prices_include_tax', TRUE)->save();
  }

  /**
   * Gets the expected response.
   *
   * @return array
   *   The data.
   */
  private function getExpected() : array {
    return [
      'purchase_country' => 'US',
      'purchase_currency' => 'USD',
      'locale' => 'en-US',
      'merchant_urls' => [
        'confirmation' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_payments',
        'notification' => 'http://localhost/payment/notify/klarna_payments?step=payment&commerce_order=1',
      ],
      'order_amount' => 240,
      'order_lines' => [
        [
          'name' => 'Test',
          'quantity' => 1,
          'unit_price' => 240,
          'total_amount' => 200,
          'total_tax_amount' => 40,
          'tax_rate' => 200000,
        ],
      ],
      'order_tax_amount' => 40,
    ];
  }

  /**
   * Tests ::createUpdateRequest().
   */
  public function testCreateUpdateRequest() : void {
    $order = $this->createOrder([
      new Adjustment([
        'type' => 'tax',
        'label' => 'VAT',
        'percentage' => '20',
        'amount' => new Price('0.4', 'USD'),
      ]),
    ]);
    $request = $this->sut->createSessionRequest([], $order);

    $this->assertEquals($this->getExpected(), $request);
  }

  /**
   * Tests ::createPlaceRequest().
   */
  public function testPlaceRequest() : void {
    $order = $this->createOrder([
      new Adjustment([
        'type' => 'tax',
        'label' => 'VAT',
        'percentage' => '20',
        'amount' => new Price('0.4', 'USD'),
      ]),
    ]);
    $request = $this->sut->createPlaceRequest([], $order);

    $this->assertEquals($this->getExpected(), $request);
  }

}
