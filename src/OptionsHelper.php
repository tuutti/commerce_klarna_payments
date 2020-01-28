<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;

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
      'color_button' => $this->t('Button color'),
      'color_button_text' => $this->t('Button text color'),
      'color_checkbox' => $this->t('Checkbox color'),
      'color_checkbox_checkmark' => $this->t('Checkbox checkmark color'),
      'color_header' => $this->t('Header color'),
      'color_link' => $this->t('Link color'),
      'color_border' => $this->t('Border color'),
      'color_border_selected' => $this->t('Border selected color'),
      'color_text' => $this->t('Text color'),
      'color_details' => $this->t('Details color'),
      'color_text_secondary' => $this->t('Secondary text color'),
      'radius_border' => $this->t('Border radius'),
    ];
  }

  /**
   * Gets the options object.
   *
   * @param \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna $plugin
   *   The payment plugin.
   *
   * @return array
   *   The options.
   */
  protected function getOptions(Klarna $plugin) : array {
    return array_filter($plugin->getConfiguration()['options']);
  }

}
