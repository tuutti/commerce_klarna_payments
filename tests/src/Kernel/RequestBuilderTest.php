<?php

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\profile\Entity\Profile;

/**
 * Request builder tests.
 *
 * @group commerce_klarna_paymnents
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
 */
class RequestBuilderTest extends RequestBuilderTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['address'];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installConfig('address');
  }

  /**
   * Gets the expected data.
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
      'order_amount' => 200,
      'order_lines' => [
        [
          'name' => 'Test',
          'quantity' => 1,
          'unit_price' => 200,
          'total_amount' => 200,
        ],
      ],
      'order_tax_amount' => 0,
    ];
  }

  /**
   * Tests ::createUpdateRequest().
   */
  public function testCreateUpdateRequest() : void {
    $request = $this->sut->createUpdateRequest([], $this->createOrder());
    $this->assertEquals($this->getExpected(), $request);
  }

  /**
   * Tests ::createPlaceRequest().
   */
  public function testCreatePlaceRequest() : void {
    $request = $this->sut->createPlaceRequest([], $this->createOrder());
    $this->assertEquals($this->getExpected(), $request);
  }

  /**
   * Tests that options are set correctly.
   */
  public function testOptions() : void {
    $options = [
      'color_button' => '#FFFFFF',
      'color_button_text' => '#FFFFFF',
      'color_checkbox' => '#FFFFFF',
      'color_checkbox_checkmark' => '#FFFFFF',
      'color_header' => '#FFFFFF',
      'color_link' => '#FFFFFF',
      'color_border' => '#FFFFFF',
      'color_border_selected' => '#FFFFFF',
      'color_text' => '#FFFFFF',
      'color_details' => '#FFFFFF',
      'color_text_secondary' => '#FFFFFF',
      'radius_border' => '5px',
    ];
    $this->gateway->setPluginConfiguration(['options' => $options])->save();

    $request = $this->sut->createUpdateRequest([], $this->createOrder());
    $this->assertEquals($options, $request['options']);
  }

  /**
   * Tests ::createCaptureRequest().
   */
  public function testCreateCaptureRequest() : void {
    $expected = [
      'captured_amount' => 200,
    ];
    $expected['order_lines'] = $this->getExpected()['order_lines'];

    $this->assertEquals($expected, $this->sut->createCaptureRequest([], $this->createOrder()));
  }

  /**
   * Tests that billing profile is set.
   */
  public function testBillingAddress() : void {
    $expected = $this->getExpected();

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
    $order
      ->setEmail('test@example.com')
      ->setBillingProfile($profile)
      ->save();

    $expected += [
      'billing_address' => [
        'email' => 'test@example.com',
        'family_name' => 'Testaaja',
        'given_name' => 'Jorma',
        'city' => 'Järvenpää',
        'country' => 'FI',
        'postal_code' => '04400',
        'street_address' => 'Mannilantie 1',
      ],
    ];

    $this->assertEquals($expected, $this->sut->createUpdateRequest([], $order));
  }

}
