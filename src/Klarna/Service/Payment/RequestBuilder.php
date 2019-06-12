<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Service\Payment;

use Drupal\commerce_klarna_payments\Klarna\Bridge\UnitBridge;
use Drupal\commerce_klarna_payments\Klarna\Service\RequestBuilderBase;
use Drupal\commerce_order\Entity\OrderItemInterface;
use KlarnaPayments\Data\Amount;
use KlarnaPayments\Data\Payment\ContactAddress;
use KlarnaPayments\Data\Payment\MerchantUrlset;
use KlarnaPayments\Data\Payment\OrderLine;
use KlarnaPayments\Data\Payment\OrderLineType;
use KlarnaPayments\Data\Payment\Session;
use KlarnaPayments\Data\ValueBase;

/**
 * Provides a request builder.
 */
class RequestBuilder extends RequestBuilderBase {

  /**
   * Populates request object for given type.
   *
   * @param string $type
   *   The request type.
   */
  public function generateRequest(string $type) : ValueBase {
    switch ($type) {
      case 'create':
      case 'update':
        return $this->createUpdateRequest(new Request());

      case 'place':
        return $this->createPlaceRequest(new AuthorizationRequest());

      case 'capture':
        return $this->createCaptureRequest(new CaptureRequest());
    }
    throw new \LogicException('Invalid type.');
  }

  /**
   * Creates capture request object.
   *
   * @param \Drupal\commerce_klarna_payments\Klarna\Data\Order\CreateCaptureInterface $request
   *   The request.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\Order\CreateCaptureInterface
   *   The capture request.
   */
  protected function createCaptureRequest(CreateCaptureInterface $request) : CreateCaptureInterface {
    $balance = $this->order->getBalance();

    $request->setCapturedAmount((int) $balance->multiply('100')->getNumber());

    foreach ($this->order->getItems() as $item) {
      $orderItem = $this->createOrderLine($item);

      $request->addOrderItem($orderItem);
    }

    return $request;
  }

  /**
   * Creates update/create session object.
   *
   * @param \KlarnaPayments\Data\Payment\Session $session
   *   The request.
   *
   * @return \KlarnaPayments\Data\Payment\Session
   *   The request.
   *
   * @todo Support shipping fees.
   * @todo Figure out what to do when order is in PENDING state.
   */
  protected function createUpdateRequest(Session $session) : Session {
    $session
      ->setPurchaseCountry($this->getStoreLanguage())
      ->setLocale($this->localeResolver->resolve($this->order))
      ->setOrderAmount(UnitBridge::toAmount($this->order->getTotalPrice()))
      ->setMerchantUrls(
        (new MerchantUrlset())
          ->setConfirmation($this->plugin->getReturnUri($this->order, 'commerce_payment.checkout.return'))
          // @todo Implement this.
          ->setNotification($this->plugin->getReturnUri($this->order, 'commerce_payment.notify', [
            'step' => 'complete',
          ]))
      )
      ->setOptions($this->getOptions());

    if ($billingAddress = $this->getBillingAddress()) {
      $session->setBillingAddress($billingAddress);
    }

    $totalTax = 0;

    foreach ($this->order->getItems() as $item) {
      $orderLine = $this->createOrderLine($item);
      $session->addOrderLine($orderLine);

      // Collect taxes only if enabled.
      if ($this->hasTaxesIncluded()) {
        // Calculate total tax amount.
        $totalTax += $orderLine->getTotalTaxAmount();
      }
    }
    $session->setOrderTaxAmount($totalTax);

    // Inspect order adjustments to include shipping fees.
    foreach ($this->order->getAdjustments() as $orderAdjustment) {
      $amount = UnitBridge::toAmount($orderAdjustment->getAmount());

      switch ($orderAdjustment->getType()) {
        case 'shipping':
          // @todo keep watching progress of https://www.drupal.org/node/2874158.
          $shippingOrderItem = (new OrderLine())
            ->setName((string) $orderAdjustment->getLabel())
            ->setQuantity(1)
            ->setUnitPrice($amount)
            ->setTotalAmount($amount)
            ->setType(OrderLineType::SHIPPING_FEE);

          $session->addOrderLine($shippingOrderItem);
          break;
      }
    }

    return $session;
  }

  /**
   * Checks whether taxes are included in prices or not.
   *
   * @return bool
   *   TRUE if taxes are included, FALSE if not.
   */
  protected function hasTaxesIncluded() : bool {
    static $taxesIncluded;

    if ($taxesIncluded === NULL) {
      if (!$this->order->getStore()->hasField('prices_include_tax')) {
        $taxesIncluded = FALSE;

        return FALSE;
      }
      $taxesIncluded = (bool) $this->order->getStore()->get('prices_include_tax');
    }
    return $taxesIncluded;
  }

  /**
   * Creates new order line.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item to create order line from.
   *
   * @return \KlarnaPayments\Data\Payment\OrderLine
   *   The order line item.
   */
  protected function createOrderLine(OrderItemInterface $item) : OrderLine {
    $unitPrice = UnitBridge::toAmount($item->getAdjustedUnitPrice());

    $orderItem = (new OrderLine())
      ->setName((string) $item->getTitle())
      ->setQuantity((int) $item->getQuantity())
      ->setUnitPrice($unitPrice)
      ->setTotalAmount(
        new Amount($unitPrice->getNumber() * $item->getQuantity(), $unitPrice->getCurrency())
      );

    foreach ($item->getAdjustments() as $adjustment) {
      // Only tax adjustments are supported by default.
      if ($adjustment->getType() !== 'tax') {
        continue;
      }
      $tax = UnitBridge::toAmount($adjustment->getAmount());

      if (!$percentage = $adjustment->getPercentage()) {
        $percentage = '0';
      }
      // Multiply tax rate to have two implicit decimals, 2500 = 25%.
      $orderItem->setTaxRate(UnitBridge::toTaxRate($percentage))
        // Calculate total tax for order item.
        ->setTotalTaxAmount($tax);
    }

    return $orderItem;
  }

  /**
   * Gets the billing address.
   *
   * @return \KlarnaPayments\Data\Payment\ContactAddress|null
   *   The billing address or null.
   */
  protected function getBillingAddress() : ? ContactAddress {
    if (!$billingData = $this->order->getBillingProfile()) {
      return NULL;
    }
    /** @var \Drupal\address\AddressInterface $address */
    $address = $billingData->get('address')->first();

    $profile = (new ContactAddress())
      ->setEmail($this->order->getEmail());

    if ($code = $address->getCountryCode()) {
      $profile->setCountry($code);
    }

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
   * Generates place order request object.
   *
   * @param \Drupal\commerce_klarna_payments\Klarna\Data\Payment\AuthorizationRequestInterface $request
   *   The authorization request.
   *
   * @return \Drupal\commerce_klarna_payments\Klarna\Data\Payment\AuthorizationRequestInterface
   *   The request.
   */
  protected function createPlaceRequest(AuthorizationRequestInterface $request) : AuthorizationRequestInterface {
    $request = $this->createUpdateRequest($request);

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
