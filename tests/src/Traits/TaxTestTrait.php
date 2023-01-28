<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Traits;

use Drupal\commerce_tax\Entity\TaxType;

/**
 * A trait to test taxes.
 */
trait TaxTestTrait {

  /**
   * Setup taxes.
   *
   * @return $this
   *   The self.
   */
  protected function setupTaxes() : self {
    // Make sure commerce_tax is enabled.
    \Drupal::moduleHandler()->getModule('commerce_tax');

    $this->installConfig([
      'commerce_tax',
    ]);

    TaxType::create([
      'id' => 'vat',
      'label' => 'VAT',
      'plugin' => 'european_union_vat',
      'configuration' => [
        'display_inclusive' => TRUE,
      ],
    ])->save();

    return $this;
  }

  /**
   * Setup prices include taxes setting.
   *
   * @param bool $included
   *   Whether to include taxes in price or not.
   * @param array $regions
   *   An array of enabled tax regions.
   *
   * @return $this
   *   The self.
   */
  protected function setPricesIncludeTax(bool $included, array $regions = []) : self {
    $this->store->set('prices_include_tax', $included)
      ->set('tax_registrations', $regions)
      ->save();
    return $this;
  }

}
