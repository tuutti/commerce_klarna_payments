<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Service\Payment;

use Drupal\commerce_klarna_payments\Klarna\Data\AddressInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\Payment\RequestInterface;
use Drupal\commerce_klarna_payments\Klarna\Request\Address;
use Drupal\commerce_klarna_payments\Klarna\Request\MerchantUrlset;
use Drupal\commerce_klarna_payments\Klarna\Request\OrderItem;
use Drupal\commerce_klarna_payments\Klarna\Request\Payment\Request;
use Drupal\commerce_klarna_payments\Klarna\Service\RequestBuilderBase;
use Drupal\commerce_price\Calculator;

/**
 * Provides a request builder.
 */
class RequestBuilder extends RequestBuilderBase {

  /**
   * Populates request object for given type.
   *
   * @param string $type
   *   The request type.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\Payment\RequestInterface
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
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\Payment\RequestInterface
   *   The request.
   */
  protected function createUpdateRequest() : RequestInterface {
    $totalPrice = $this->order->getTotalPrice();

    $request = (new Request())
      ->setPurchaseCountry($this->getStoreLanguage())
      ->setPurchaseCurrency($totalPrice->getCurrencyCode())
      ->setLocale($this->plugin->getLocale())
      ->setOrderAmount((int) $totalPrice->multiply('100')->getNumber())
      ->setMerchantUrls(
        new MerchantUrlset([
          'confirmation' => $this->plugin->getReturnUri($this->order, 'commerce_payment.checkout.return'),
          'notification' => $this->plugin->getReturnUri($this->order, 'commerce_payment.notify', [
            'step' => 'complete',
          ]),
          // @todo Implement?
          // 'push' => 'test',
        ])
      )
      ->setOptions($this->getOptions());

    if ($billingAddress = $this->getBillingAddress()) {
      $request->setBillingAddress($billingAddress);
    }
    $totalTax = 0;

    foreach ($this->order->getItems() as $item) {
      $unitPrice = (int) $item->getUnitPrice()->multiply('100')->getNumber();

      $orderItem = (new OrderItem())
        ->setName($item->getTitle())
        ->setQuantity((int) $item->getQuantity())
        ->setUnitPrice($unitPrice)
        ->setTotalAmount((int) ($unitPrice * $item->getQuantity()));

      foreach ($item->getAdjustments() as $adjustment) {
        // Only tax adjustments are supported by default.
        if ($adjustment->getType() !== 'tax') {
          continue;
        }
        $tax = (int) $adjustment->getAmount()->multiply('100')->getNumber();
        // Calculate order's total tax.
        $totalTax += $tax;

        if (!$percentage = $adjustment->getPercentage()) {
          $percentage = '0';
        }
        // Multiply tax rate to have two implicit decimals, 2500 = 25%.
        $orderItem->setTaxRate((int) Calculator::multiply($percentage, '10000'))
          // Calculate total tax for order item.
          ->setTotalTaxAmount((int) ($tax * $item->getQuantity()));
      }
      $request->addOrderItem($orderItem);
    }
    $request->setOrderTaxAmount($totalTax);

    return $request;
  }

  /**
   * Gets the billing address.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\AddressInterface|null
   *   The billing address or null.
   */
  protected function getBillingAddress() : ? AddressInterface {
    if (!$billingData = $this->order->getBillingProfile()) {
      return NULL;
    }
    /** @var \Drupal\address\AddressInterface $address */
    $address = $billingData->get('address')->first();

    $profile = (new Address())
      ->setEmail($this->order->getEmail())
      // Country must be populated if we update billing details.
      ->setCountry($address->getCountryCode());

    if ($city = $address->getLocality()) {
      $profile->setCity($city);
    }

    if ($addr = $address->getAddressLine1()) {
      $profile->setStreetAddress($addr);
    }

    if ($addr2 = $address->getAddressLine2()) {
      $profile->setStreetAddress2($addr2);
    }

    if ($firstName = $address->getGivenName()) {
      $profile->setGivenName($firstName);
    }

    if ($lastName = $address->getFamilyName()) {
      $profile->setFamilyName($lastName);
    }

    if ($postalCode = $address->getPostalCode()) {
      $profile->setPostalCode($postalCode);
    }

    return $profile;
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
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\Payment\RequestInterface
   *   The request.
   */
  protected function createPlaceRequest() : RequestInterface {
    $request = $this->createUpdateRequest();

    $this->validate($request, [
      'purchase_country',
      'purchase_currency',
      'locale',
      'order_amount',
      'order_lines',
      'merchant_urls',
      'billing_address',
    ]);
    return $request;
  }

  /**
   * Validates the requests.
   *
   * @param \Drupal\commerce_klarna_payments\Klarna\Data\Payment\RequestInterface $request
   *   The request.
   * @param array $required
   *   Required fields.
   */
  protected function validate(RequestInterface $request, array $required) : void {
    $values = $request->toArray();

    $missing = array_filter($required, function ($key) use ($values) {
      return !isset($values[$key]);
    });

    if (count($missing) > 0) {
      throw new \InvalidArgumentException(sprintf('Missing required values: %s', implode(',', $missing)));
    }
  }

}
