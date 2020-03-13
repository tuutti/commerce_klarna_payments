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
   * @param array $capture
   *   The capture.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The capture request.
   */
  public function createCaptureRequest(array $capture, OrderInterface $order) : array {
    $balance = $order->getBalance();

    $capture += [
      'captured_amount' => UnitConverter::toAmount($balance),
    ];

    foreach ($order->getItems() as $item) {
      $orderItem = $this->createOrderLine($item);

      $capture['order_lines'][] = $orderItem;
    }

    return $capture;
  }

  /**
   * Generates place order request object.
   *
   * @param array $session
   *   The existing session data.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The order object.
   */
  public function createPlaceRequest(array $session, OrderInterface $order) : array {
    $session = $this->createUpdateRequest($session, $order);
    return $session;
  }

  /**
   * Creates update/create session object.
   *
   * @param array $session
   *   The existing session data.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The request data.
   *
   * @todo Support promotions.
   * @todo Figure out what to do when order is in PENDING state.
   */
  public function createUpdateRequest(array $session, OrderInterface $order) : array {
    $plugin = $this->getPaymentPlugin($order);

    $session += [
      'purchase_country' => $order->getStore()->getAddress()->getCountryCode(),
      'locale' => $this->getLocale(),
      'purchase_currency' => $order->getTotalPrice()->getCurrencyCode(),
      'order_amount' => UnitConverter::toAmount($order->getTotalPrice()),
      'merchant_urls' => [
        'confirmation' => $plugin->getReturnUri($order, 'commerce_payment.checkout.return'),
        'notification' => $plugin->getReturnUri($order, 'commerce_payment.notify', [
          'step' => 'complete',
        ]),
      ],
    ];

    if ($options = $this->getOptions($plugin)) {
      $session['options'] = $options;
    }

    if ($billingAddress = $this->getBillingAddress($order)) {
      $session['billing_address'] = $billingAddress;
    }

    $totalTax = 0;

    foreach ($order->getItems() as $item) {
      $orderLine = $this->createOrderLine($item);
      $session['order_lines'][] = $orderLine;

      // Collect taxes only if enabled.
      if ($this->hasTaxesIncluded($order) && isset($orderLine['total_tax_amount'])) {
        // Calculate total tax amount.
        $totalTax += $orderLine['total_tax_amount'];
      }
    }
    $session['order_tax_amount'] = $totalTax;

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
          $session['order_lines'][] = $shippingOrderItem;
          break;
      }
    }

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
   * @return array
   *   The order line item.
   */
  protected function createOrderLine(OrderItemInterface $item) : array {
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

    return $orderItem;
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
