<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface;

/**
 * Provides a helper trait for Klarna's options setting.
 */
trait OptionsHelper {

  /**
   * List of default options.
   *
   * @return array
   *   The default options.
   */
  protected function getDefaultOptions() : array {
    return [
      'color_border' => $this->t('Border color'),
      'color_border_selected' => $this->t('Border selected color'),
      'color_text' => $this->t('Text color'),
      'color_details' => $this->t('Details color'),
      'radius_border' => $this->t('Border radius'),
    ];
  }

  /**
   * Gets the options object.
   *
   * @param \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface $plugin
   *   The payment plugin.
   *
   * @return array
   *   The options.
   */
  protected function getOptions(KlarnaInterface $plugin) : array {
    return array_filter($plugin->getConfiguration()['options']);
  }

}
