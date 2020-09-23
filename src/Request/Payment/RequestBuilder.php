<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Request\Payment;

use Drupal\address\AddressInterface;
use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\OptionsHelper;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
   * @return \Klarna\Model\Capture
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

    if ($billingAddress = $this->getAddress($order, 'billing')) {
      $session->setBillingAddress(new Ordersaddress($billingAddress));
    }

    if ($shippingAddress = $this->getAddress($order, 'shipping')) {
      $session->setShippingAddress(new Ordersaddress($shippingAddress));
    }

    $totalTax   = 0;
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

    // Add shipping info, if present.
    if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
      $shipments = $order->get('shipments')->referencedEntities();

      foreach ($shipments as $shipment) {
        $amount = UnitConverter::toAmount($shipment->getAmount());

        $shippingOrderItem = (new OrdersorderLine())
          // We use the same label the shipping adjustments are using,
          // which is better than the generic shipment labels.
          ->setName((string) new TranslatableMarkup('Shipping'))
          ->setQuantity(1)
          ->setUnitPrice($amount)
          ->setTotalAmount($amount)
          ->setType('shipping_fee');

        foreach ($shipment->getAdjustments(['tax']) as $taxAdjustment) {
          if (!$percentage = $taxAdjustment->getPercentage()) {
            $percentage = '0';
          }
          $shippingOrderItem->setTaxRate(UnitConverter::toTaxRate($percentage))
            ->setTotalTaxAmount(UnitConverter::toAmount($taxAdjustment->getAmount()));

          $totalTax += $shippingOrderItem->getTotalTaxAmount();
        }

        $orderLines[] = $shippingOrderItem;
      }
    }

    $session->setOrderTaxAmount($totalTax)
      ->setOrderLines($orderLines);

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

    $orderItem = (new OrdersorderLine())
      ->setName($item->getTitle())
      ->setQuantity((int) $item->getQuantity())
      ->setUnitPrice($unitPrice)
      ->setTotalAmount($totalPrice);

    foreach ($item->getAdjustments(['tax']) as $adjustment) {
      if (!$percentage = $adjustment->getPercentage()) {
        $percentage = '0';
      }

      $orderItem->setTaxRate(UnitConverter::toTaxRate($percentage))
        ->setTotalTaxAmount(UnitConverter::toAmount($adjustment->getAmount()));
    }

    return $orderItem;
  }

  /**
   * Gets the billing address.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $type
   *   The address type (shipping or billing for example).
   *
   * @return array|null
   *   The billing address or null.
   */
  protected function getAddress(OrderInterface $order, string $type) : ? array {
    $profiles = $order->collectProfiles();

    if (empty($profiles[$type])) {
      return NULL;
    }
    $profile = $profiles[$type];

    $data = [
      'email' => $order->getEmail(),
    ];

    /** @var \Drupal\address\AddressInterface $address */
    $address = $profile->get('address')->first();

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
