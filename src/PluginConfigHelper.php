<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments;

use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna;
use Drupal\Core\Language\LanguageManagerInterface;
use Klarna\Rest\Transport\ConnectorInterface;

/**
 * Provides a configuration helper.
 */
final class PluginConfigHelper {

  /**
   * Constructs a new instance.
   */
  public function __construct(LanguageManagerInterface $languageManager) {
    $this->languageManager = $languageManager;
  }

  public function getLocale() : string {
    $current = $this->languageManager->getCurrentLanguage()->getId();
  }

}
