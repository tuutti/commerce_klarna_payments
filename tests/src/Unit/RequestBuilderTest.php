<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Unit;

use Drupal\address\AddressInterface;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface;
use Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Request builder unit tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
 */
class RequestBuilderTest extends UnitTestCase {

  /**
   * Creates an order mock object.
   *
   * @return \Prophecy\Prophecy\ObjectProphecy
   *   The mock object.
   */
  private function createOrderMock() : ObjectProphecy {
    $orderItem = $this->prophesize(OrderItemInterface::class);
    $orderItem->getTitle()
      ->willReturn('Title');
    $orderItem->getQuantity()
      ->willReturn(1);
    $orderItem->getAdjustedUnitPrice()
      ->willReturn(new Price('11', 'EUR'));
    $orderItem->getAdjustedTotalPrice()
      ->willReturn(new Price('11', 'EUR'));
    $orderItem->getAdjustments(Argument::any())
      ->willReturn([]);
    $orderItem->getPurchasedEntity()->willReturn(NULL);

    $order = $this->prophesize(OrderInterface::class);
    $order->id()->willReturn('1');
    $order->getTotalPrice()->willReturn(new Price('11', 'EUR'));
    $order->collectProfiles()->willReturn([]);
    $order->getItems()->willReturn([$orderItem->reveal()]);
    $order->hasField('shipments')->willReturn(FALSE);

    $paymentGateway = $this->prophesize(PaymentGatewayInterface::class);
    $klarnaPlugin = $this->prophesize(KlarnaInterface::class);
    $klarnaPlugin->getReturnUri(Argument::any(), Argument::any(), Argument::any())
      ->willReturn('https://localhost');
    $klarnaPlugin->getConfiguration()->willReturn(['options' => []]);
    $paymentGateway->getPlugin()->willReturn($klarnaPlugin->reveal());
    $order->payment_gateway = (object) ['entity' => $paymentGateway->reveal()];

    return $order;
  }

  /**
   * Tests that purchase country can be set.
   */
  public function testPurchaseCountry() : void {
    $order = $this->createOrderMock();
    $store = $this->prophesize(StoreInterface::class);
    $address = $this->prophesize(AddressInterface::class);
    $address->getCountryCode()->willReturn('fi');
    $store->getAddress()->willReturn($address->reveal());
    $order->getStore()->willReturn($store->reveal());

    $language = $this->prophesize(LanguageInterface::class);
    $language->getId()->willReturn('fi');
    $languageManager = $this->prophesize(LanguageManagerInterface::class);
    $languageManager->getCurrentLanguage()->willReturn($language->reveal());

    $sut = new RequestBuilder($languageManager->reveal());
    $request = $sut->createSessionRequest($order->reveal());

    // Make sure purchase country defaults to store's country.
    $this->assertEquals('fi', $request->getPurchaseCountry());

    $address->getCountryCode()->willReturn('se');
    $order->get('billing_profile')->willReturn((object) ['entity' => $address->reveal()]);
    $request = $sut->createSessionRequest($order->reveal());

    // Make sure purchase country is overridden by billing address.
    $this->assertEquals('se', $request->getPurchaseCountry());
  }

  /**
   * Tests that locale can be empty if purchase country is not set.
   */
  public function testEmptyLocale() : void {
    $order = $this->createOrderMock();
    $store = $this->prophesize(StoreInterface::class);
    $store->getAddress()->willReturn(NULL);
    $order->getStore()->willReturn($store->reveal());

    $sut = new RequestBuilder($this->prophesize(LanguageManagerInterface::class)->reveal());
    $request = $sut->createSessionRequest($order->reveal());

    $this->assertNull($request->getLocale());
  }

  /**
   * Tests the locale.
   *
   * @covers ::createSessionRequest
   * @covers ::getLocale
   * @dataProvider localeTestData
   */
  public function testLocale(string $expected, string $interface, string $country) : void {
    $order = $this->createOrderMock();
    $store = $this->prophesize(StoreInterface::class);
    $address = $this->prophesize(AddressInterface::class);
    $address->getCountryCode()->willReturn($country);
    $store->getAddress()->willReturn($address->reveal());
    $order->getStore()->willReturn($store->reveal());

    $language = $this->prophesize(LanguageInterface::class);
    $language->getId()->willReturn($interface);
    $languageManager = $this->prophesize(LanguageManagerInterface::class);
    $languageManager->getCurrentLanguage()->willReturn($language->reveal());

    $sut = new RequestBuilder($languageManager->reveal());
    $request = $sut->createSessionRequest($order->reveal());

    $this->assertEquals($expected, $request->getLocale());
  }

  /**
   * Provides a test data for testLocale().
   *
   * @return \string[][]
   *   The test data.
   */
  public function localeTestData() : array {
    return [
      // AT.
      ['de-AT', 'de', 'at'],
      ['en-AT', 'en', 'at'],
      ['de-AT', 'fi', 'at'],
      // DK.
      ['da-DK', 'da', 'dk'],
      ['en-DK', 'en', 'dk'],
      ['da-DK', 'fi', 'dk'],
      // DE.
      ['de-DE', 'de', 'de'],
      ['en-DE', 'en', 'de'],
      ['de-DE', 'fi', 'de'],
      // FI.
      ['fi-FI', 'fi', 'fi'],
      ['en-FI', 'en', 'fi'],
      ['sv-FI', 'sv', 'fi'],
      ['fi-FI', 'it', 'fi'],
      // NL.
      ['nl-NL', 'nl', 'nl'],
      ['en-NL', 'en', 'nl'],
      ['nl-NL', 'fi', 'nl'],
      // NO.
      ['nb-NO', 'nb', 'no'],
      ['nb-NO', 'nn', 'no'],
      ['en-NO', 'en', 'no'],
      ['nb-NO', 'fi', 'no'],
      // SE.
      ['sv-SE', 'sv', 'se'],
      ['en-SE', 'en', 'se'],
      ['sv-SE', 'fi', 'se'],
      // CH.
      ['de-CH', 'de', 'ch'],
      ['fr-CH', 'fr', 'ch'],
      ['it-CH', 'it', 'ch'],
      ['en-CH', 'en', 'ch'],
      ['de-CH', 'fi', 'ch'],
      // GB.
      ['en-GB', 'en', 'gb'],
      ['en-GB', 'fi', 'gb'],
      // US.
      ['en-US', 'en', 'us'],
      ['en-US', 'fi', 'us'],
      // AU.
      ['en-AU', 'en', 'au'],
      ['en-AU', 'fi', 'au'],
      // BE.
      ['nl-BE', 'nl', 'be'],
      ['fr-BE', 'fr', 'be'],
      ['nl-BE', 'en', 'be'],
      // ES.
      ['es-ES', 'en', 'es'],
      ['es-ES', 'es', 'es'],
      // IT.
      ['it-IT', 'it', 'it'],
      ['it-IT', 'en', 'it'],
      // Non-supported country.
      ['en-US', 'ru', 'ru'],
    ];
  }

}
