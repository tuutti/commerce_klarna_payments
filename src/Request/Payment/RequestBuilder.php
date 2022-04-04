<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Request\Payment;

use Drupal\address\AddressInterface;
use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\OptionsHelper;
use Drupal\commerce_klarna_payments\PaymentPluginTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Klarna\OrderManagement\Model\Capture;
use Klarna\Payments\Model\MerchantUrls;
use Klarna\Payments\Model\Options;
use Klarna\Payments\Model\Address;
use Klarna\Payments\Model\OrderLine;
use Klarna\Payments\Model\Session;

/**
 * Provides a request builder.
 */
class RequestBuilder {

  use OptionsHelper;
  use PaymentPluginTrait;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected LanguageManagerInterface $languageManager
  ) {
  }

  /**
   * Creates capture request object.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\OrderManagement\Model\Capture
   *   The capture object.
   */
  public function createCaptureRequest(OrderInterface $order) : Capture {
    $capture = new Capture();
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
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna\Payments\Model\Session
   *   The request data.
   */
  public function createSessionRequest(OrderInterface $order) : Session {
    $plugin = $this->getPaymentPlugin($order);

    $session = new Session();

    // Default purchase country to store country.
    if ($storeAddress = $order->getStore()->getAddress()) {
      $session->setPurchaseCountry($storeAddress->getCountryCode());
    }

    $session
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
      $session->setBillingAddress($billingAddress);

      // Override purchase country from billing address.
      if ($billingAddress->getCountry()) {
        $session->setPurchaseCountry($billingAddress->getCountry());
      }
    }

    if ($shippingAddress = $this->getAddress($order, 'shipping')) {
      $session->setShippingAddress($shippingAddress);
    }

    if ($purchaseCountry = $session->getPurchaseCountry()) {
      $session->setLocale($this->getLocale($purchaseCountry));
    }

    $orderLines = [];

    foreach ($order->getItems() as $item) {
      $orderLines[] = $this->createOrderLine($item);
    }

    // Add shipping info, if present.
    if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
      $shipments = $order->get('shipments')->referencedEntities();

      foreach ($shipments as $shipment) {
        $amount = UnitConverter::toAmount($shipment->getAmount());

        $shippingOrderItem = (new OrderLine())
          // We use the same label the shipping adjustments are using,
          // which is better than the generic shipment labels.
          ->setName((string) new TranslatableMarkup('Shipping'))
          ->setQuantity(1)
          ->setUnitPrice($amount)
          ->setTotalAmount($amount)
          ->setType('shipping_fee');

        foreach ($shipment->getAdjustments(['tax']) as $taxAdjustment) {
          $shippingOrderItem->setTaxRate(UnitConverter::toTaxRate($taxAdjustment->getPercentage()))
            ->setTotalTaxAmount(UnitConverter::toAmount($taxAdjustment->getAmount()));
        }
        $orderLines[] = $shippingOrderItem;
      }
    }
    $totalTax = 0;

    foreach ($orderLines as $orderLine) {
      if (!$orderLine->getTotalTaxAmount()) {
        continue;
      }
      $totalTax += $orderLine->getTotalTaxAmount();
    }

    $session->setOrderTaxAmount($totalTax)
      ->setOrderLines($orderLines);

    return $session;
  }

  /**
   * Creates new order line.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item to create order line from.
   *
   * @return \Klarna\Payments\Model\OrderLine
   *   The order line item.
   */
  protected function createOrderLine(OrderItemInterface $item) : OrderLine {
    $orderItem = (new OrderLine())
      ->setName($item->getTitle())
      ->setQuantity((int) $item->getQuantity())
      ->setUnitPrice(UnitConverter::toAmount($item->getAdjustedUnitPrice()))
      ->setTotalAmount(UnitConverter::toAmount($item->getAdjustedTotalPrice()));

    if ($purchasedEntity = $item->getPurchasedEntity()) {
      // Use purchased entity type + id as reference by default. For example:
      // commerce_product_variation:1.
      $reference = sprintf('%s:%s', $purchasedEntity->getEntityTypeId(), $purchasedEntity->id());

      if ($purchasedEntity instanceof ProductVariationInterface) {
        $reference = $purchasedEntity->getSku();
      }
      $orderItem->setReference($reference);
    }

    foreach ($item->getAdjustments(['tax']) as $adjustment) {
      $orderItem->setTaxRate(UnitConverter::toTaxRate($adjustment->getPercentage()))
        ->setTotalTaxAmount(UnitConverter::toAmount($adjustment->getAmount()));
    }

    return $orderItem;
  }

  /**
   * Attempts to map ISO locale to RFC 1766 locale.
   *
   * @param string $country
   *   The country.
   *
   * @see https://docs.klarna.com/klarna-payments/in-depth-knowledge/puchase-countries-currencies-locales/
   *
   * @return string
   *   The RFC 1766 locale.
   */
  protected function getLocale(string $country) : string {
    $language = strtolower($this->languageManager->getCurrentLanguage()->getId());
    $country = strtolower($country);

    $map = [
      'at' => ['de' => 'de-AT', 'en' => 'en-AT'],
      'dk' => ['da' => 'da-DK', 'en' => 'en-DK'],
      'de' => ['de' => 'de-DE', 'en' => 'en-DE'],
      'fi' => ['fi' => 'fi-FI', 'en' => 'en-FI', 'sv' => 'sv-FI'],
      'nl' => ['nl' => 'nl-NL', 'en' => 'en-NL'],
      'no' => ['nb' => 'nb-NO', 'nn' => 'nb-NO', 'en' => 'en-NO'],
      'se' => ['sv' => 'sv-SE', 'en' => 'en-SE'],
      'ch' => [
        'de' => 'de-CH',
        'fr' => 'fr-CH',
        'it' => 'it-CH',
        'en' => 'en-CH',
      ],
      'gb' => ['en' => 'en-GB'],
      'us' => ['en' => 'en-US'],
      'au' => ['en' => 'en-AU'],
      'be' => ['nl' => 'nl-BE', 'fr' => 'fr-BE'],
      'es' => ['es' => 'es-ES'],
      'it' => ['it' => 'it-IT'],
    ];

    if (isset($map[$country])) {
      // Attempt to serve different language based on interface language. Like
      // fi-EN when interface language is set to english.
      if (isset($map[$country][$language])) {
        return $map[$country][$language];
      }
      // Default to first available language.
      return reset($map[$country]);
    }

    return 'en-US';
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
  protected function getAddress(OrderInterface $order, string $type) : ? Address {
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
        'region' => $address->getAdministrativeArea(),
        'organization_name' => $address->getOrganization(),
      ];
    }

    return new Address(array_filter($data));
  }

}
