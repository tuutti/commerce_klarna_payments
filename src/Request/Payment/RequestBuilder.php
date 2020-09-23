<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Request\Payment;

use Drupal\address\AddressInterface;
use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\OptionsHelper;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Klarna\Model\Capture;
use Klarna\Model\MerchantUrls;
use Klarna\Model\Options;
use Klarna\Model\Ordersaddress;
use Klarna\Model\OrdersorderLine;
use Klarna\Model\Session;

/**
 * Provides a request builder.
 */
class RequestBuilder {

  use OptionsHelper;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    LanguageManagerInterface $languageManager
  ) {
    $this->languageManager = $languageManager;
  }

  /**
   * Creates capture request object.
   *
   * @param \Klarna\Model\Capture $capture
   *   The capture.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The capture object.
   */
  public function createCaptureRequest(Capture $capture, OrderInterface $order) : Capture {
    $orderLines = [];

    foreach ($order->getItems() as $item) {
      $orderLines[] = $this->createOrderLine($item);
    }

    $capture->setOrderLines($orderLines)
      ->setCapturedAmount(UnitConverter::toAmount($order->getBalance()));

    return $capture;
  }

  /**
   * Creates update/create session object.
   *
   * @param \Klarna\Model\Session $session
   *   The existing session data.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Model\Session
   *   The request data.
   *
   * @todo Support promotions.
   * @todo Figure out what to do when order is in PENDING state.
   */
  public function createUpdateRequest(Session $session, OrderInterface $order) : Session {
    $plugin = $this->getPaymentPlugin($order);

    if ($address = $order->getStore()->getAddress()) {
      $session->setPurchaseCountry($address->getCountryCode());
    }

    $session
      ->setLocale($this->getLocale())
      ->setPurchaseCurrency($order->getTotalPrice()->getCurrencyCode())
      ->setOrderAmount(UnitConverter::toAmount($order->getTotalPrice()))
      ->setMerchantUrls(new MerchantUrls([
        'confirmation' => $plugin->getReturnUri($order, 'commerce_payment.checkout.return'),
        'notification' => $plugin->getReturnUri($order, 'commerce_payment.notify', [
          'step' => 'complete',
        ]),
      ]));

    if ($options = $this->getOptions($plugin)) {
      $session->setOptions(new Options($options));
    }

    if ($billingAddress = $this->getBillingAddress($order)) {
      $session->setBillingAddress(new Ordersaddress($billingAddress));
    }

    $totalTax = 0;

    $orderLines = [];

    foreach ($order->getItems() as $item) {
      $orderLine = $this->createOrderLine($item);
      $orderLines[] = $orderLine;

      // Collect taxes only if enabled.
      if ($this->hasTaxesIncluded($order) && $orderLine->getTotalTaxAmount() !== NULL) {
        // Calculate total tax amount.
        $totalTax += $orderLine->getTotalTaxAmount();
      }
    }

    $session->setOrderTaxAmount($totalTax);

    // Inspect order adjustments to include shipping fees.
    foreach ($order->getAdjustments() as $adjustment) {
      $amount = UnitConverter::toAmount($adjustment->getAmount());

      switch ($adjustment->getType()) {
        case 'shipping':
          // @todo keep watching progress of https://www.drupal.org/node/2874158.
          $shippingOrderItem = [
            'name' => $adjustment->getLabel(),
            'quantity' => 1,
            'unit_price' => $amount,
            'total_amount' => $amount,
            'type' => 'shipping',
          ];
          $orderLines[] = new OrdersorderLine($shippingOrderItem);
          break;
      }
    }

    $session->setOrderLines($orderLines);

    return $session;
  }

  /**
   * Attempts to map ISO locale to RFC 1766 locale.
   *
   * @return string
   *   The RFC 1766 locale.
   */
  protected function getLocale() : string {
    $locale = strtolower($this->languageManager->getCurrentLanguage()->getId());

    $map = [
      'fi' => 'fi-FI',
      'sv' => 'sv-SV',
      'nb' => 'nb-NO',
      'nn' => 'nn-NO',
      'de' => 'de-DE',
      // Drupal does not define these by default.
      'at' => 'de-AT',
    ];

    return $map[$locale] ?? 'en-US';
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
    return $order->payment_gateway->entity->getPlugin();
  }

  /**
   * Checks whether taxes are included in prices or not.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return bool
   *   TRUE if taxes are included, FALSE if not.
   */
  protected function hasTaxesIncluded(OrderInterface $order) : bool {
    static $taxesIncluded;

    if ($taxesIncluded === NULL) {
      if (!$order->getStore()->hasField('prices_include_tax')) {
        $taxesIncluded = FALSE;

        return FALSE;
      }
      $taxesIncluded = (bool) $order->getStore()->get('prices_include_tax');
    }
    return $taxesIncluded;
  }

  /**
   * Creates new order line.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item to create order line from.
   *
   * @return \Klarna\Model\OrdersorderLine
   *   The order line item.
   */
  protected function createOrderLine(OrderItemInterface $item) : OrdersorderLine {
    $unitPrice = UnitConverter::toAmount($item->getAdjustedUnitPrice());
    $totalPrice = UnitConverter::toAmount($item->getTotalPrice());

    $orderItem = [
      'name' => $item->getTitle(),
      'quantity' => (int) $item->getQuantity(),
      'unit_price' => $unitPrice,
      'total_amount' => $totalPrice,
    ];

    foreach ($item->getAdjustments() as $adjustment) {
      // Only tax adjustments are supported by default.
      if ($adjustment->getType() !== 'tax') {
        continue;
      }
      $tax = UnitConverter::toAmount($adjustment->getAmount());

      if (!$percentage = $adjustment->getPercentage()) {
        $percentage = '0';
      }

      $orderItem += [
        'tax_rate' => UnitConverter::toTaxRate($percentage),
        'total_tax_amount' => $tax,
      ];
    }

    return new OrdersorderLine($orderItem);
  }

  /**
   * Gets the billing address.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array|null
   *   The billing address or null.
   */
  protected function getBillingAddress(OrderInterface $order) : ? array {
    if (!$billingData = $order->getBillingProfile()) {
      return NULL;
    }

    $data = [
      'email' => $order->getEmail(),
    ];

    /** @var \Drupal\address\AddressInterface $address */
    $address = $billingData->get('address')->first();

    if ($address instanceof AddressInterface) {
      $data += [
        'family_name' => $address->getFamilyName(),
        'given_name' => $address->getGivenName(),
        'city' => $address->getLocality(),
        'country' => $address->getCountryCode(),
        'postal_code' => $address->getPostalCode(),
        'street_address' => $address->getAddressLine1(),
        'street_address2' => $address->getAddressLine2(),
      ];
    }

    return array_filter($data);
  }

}
