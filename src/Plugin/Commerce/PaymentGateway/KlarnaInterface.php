<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway;

/**
 * Provides Klarna API addresses.
 */
interface KlarnaInterface {

  /**
   * List of regions.
   *
   * @var array
   */
  public const REGIONS = [
    'eu' => [
      'live' => 'https://api.klarna.com',
      'test' => 'https://api.playground.klarna.com',
    ],
    'na' => [
      'live' => 'https://api-na.klarna.com',
      'test' => 'https://api-na.playground.klarna.com',
    ],
    'oc' => [
      'live' => 'https://api-oc.klarna.com',
      'test' => 'https://api-oc.playground.klarna.com',
    ],
  ];

  /**
   * Gets the region.
   *
   * @return string
   *   The region.
   */
  public function getRegion() : string;

  /**
   * Gets the live mode status.
   *
   * @return bool
   *   Boolean indicating whether we are operating in live mode.
   */
  public function isLive() : bool;

  /**
   * Gets the host.
   *
   * @return string
   *   The host.
   */
  public function getHost() : string;

}
