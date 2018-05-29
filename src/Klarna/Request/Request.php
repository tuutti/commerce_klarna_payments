<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Request;

use Drupal\commerce_klarna_payments\Klarna\Data\AddressInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\AttachmentInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\CustomerInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\ObjectInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\OptionsInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\OrderItemInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\RequestInterface;
use Drupal\commerce_klarna_payments\Klarna\Data\UrlsetInterface;
use Webmozart\Assert\Assert;

/**
 * Value object for making requests.
 */
class Request implements RequestInterface {

  protected $localeMapping = [
    'sv-se' => 'SE',
    'fi-fi' => 'FI',
    'sv-fi' => 'FI',
    'nb-no' => 'NO',
    'de-de' => 'DE',
    'de-at' => 'AT',
    'en-us' => 'US',
    'en-gb' => 'GB',
  ];

  /**
   * The container for data used in request.
   *
   * @var array
   */
  protected $data = [];

  /**
   * {@inheritdoc}
   */
  public function setDesign(string $design) : RequestInterface {
    $this->data['design'] = $design;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setPurchaseCountry(string $country) : RequestInterface {
    $this->data['purchase_country'] = $country;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setPurchaseCurrency(string $currency) : RequestInterface {
    $this->data['purchase_currency'] = $currency;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setLocale(string $locale, array $additionalLocales = []) : RequestInterface {
    $locales = array_merge($this->localeMapping, $additionalLocales);

    if (!array_key_exists($locale, $locales)) {
      throw new \InvalidArgumentException('Invalid locale');
    }
    $this->data['locale'] = $locale;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setBillingAddress(AddressInterface $address) : RequestInterface {
    $this->data['billing_address'] = $address;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setShippingAddress(AddressInterface $address) : RequestInterface {
    $this->data['shipping_address'] = $address;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOrderAmount(int $amount) : RequestInterface {
    $this->data['order_amount'] = $amount;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOrderTaxAmount(int $amount) : RequestInterface {
    $this->data['order_tax_amount'] = $amount;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOrderItems(array $orderItems) : RequestInterface {
    Assert::allIsInstanceOf($orderItems, OrderItemInterface::class);

    $this->data['order_lines'] = $orderItems;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addOrderItem(OrderItemInterface $orderItem) : RequestInterface {
    $this->data['order_lines'][] = $orderItem;

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomer(CustomerInterface $customer) : RequestInterface {
    $this->data['customer'] = $customer;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setMerchantUrls(UrlsetInterface $urlset) : RequestInterface {
    $this->data['merchant_urls'] = $urlset;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setMerchantReference1(string $reference) : RequestInterface {
    $this->data['merchant_reference1'] = $reference;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setMerchantReference2(string $reference) : RequestInterface {
    $this->data['merchant_reference2'] = $reference;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setMerchantData(string $data) : RequestInterface {
    $this->data['merchant_data'] = $data;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOptions(OptionsInterface $options) : RequestInterface {
    $this->data['options'] = $options;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setAttachments(AttachmentInterface $attachment) : RequestInterface {
    $this->data['attachments'] = $attachment;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomPaymentMethodIds(array $ids) : RequestInterface {
    $this->data['custom_payment_method_ids'] = $ids;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addCustomPaymentMethodId(string $method) : RequestInterface {
    $this->data['custom_payment_method_ids'][] = $method;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray() : array {
    $data = [];
    foreach ($this->data as $key => $value) {
      $normalized = $value;

      if ($value instanceof ObjectInterface) {
        // Normalize object values.
        $normalized = $value->toArray();
      }

      // Handle multivalue fields.
      if (is_array($value)) {
        $normalized = [];
        foreach ($value as $k => $v) {
          $normalized[] = $v->toArray();
        }
      }
      // Convert empty arrays to NULL.
      if (is_array($normalized) && !$normalized) {
        $normalized = NULL;
      }
      $data[$key] = $normalized;
    }

    return $data;
  }

}
