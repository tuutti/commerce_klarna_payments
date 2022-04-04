<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Klarna\Configuration;

/**
 * Provides Klarna API addresses.
 */
interface KlarnaInterface extends OffsitePaymentGatewayInterface {

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
   * Gets the return url.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $routeName
   *   The route.
   * @param array $arguments
   *   An additional arguments.
   *
   * @return string
   *   The URL.
   */
  public function getReturnUri(OrderInterface $order, string $routeName, array $arguments = []) : string;

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

  /**
   * Gets the client configuration.
   *
   * @return \Klarna\Configuration
   *   The configuration.
   */
  public function getClientConfiguration() : Configuration;

  /**
   * Logs the debug message.
   *
   * @param string $markup
   *   The markup to log.
   * @param array $context
   *   The context.
   */
  public function debug(string $markup, array $context = []) : void;

}
