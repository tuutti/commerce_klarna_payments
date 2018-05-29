<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Payment;

use Klarna\Rest\Resource;
use Klarna\Rest\Transport\Connector;

/**
 * Create payment authorization.
 */
class Authorization extends Resource {

  /**
   * {@inheritdoc}
   */
  public const ID_FIELD = 'authorization_token';

  /**
   * {@inheritdoc}
   */
  public static $path = '/payments/v1/authorizations';

  /**
   * The authorization token.
   *
   * @var string
   */
  protected $authorizationToken;

  /**
   * Constructs a new instance.
   *
   * @param \Klarna\Rest\Transport\Connector $connector
   *   The connector.
   * @param string $authorizationToken
   *   The authorization token.
   */
  public function __construct(Connector $connector, string $authorizationToken) {
    parent::__construct($connector);

    $this->setLocation(sprintf('%s/%s', self::$path, $authorizationToken));
  }

  /**
   * Creates the order.
   *
   * @param array $data
   *   The data.
   *
   * @return $this
   *   The self.
   */
  public function create(array $data) {
    $data = $this->post(sprintf('%s/order', $this->getLocation()), $data)
      ->status('200')
      ->contentType('application/json')
      ->getJson();
    $this->exchangeArray($data);

    return $this;
  }

}
