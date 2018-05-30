<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna\Rest;

use Klarna\Rest\Resource;
use Klarna\Rest\Transport\Connector;

/**
 * Create payment session.
 */
class Session extends Resource {

  /**
   * {@inheritdoc}
   */
  public const ID_FIELD = 'session_id';

  /**
   * {@inheritdoc}
   */
  public static $path = '/payments/v1/sessions';

  /**
   * Constructs a new instance.
   *
   * @param \Klarna\Rest\Transport\Connector $connector
   *   The connector.
   * @param string|null $sessionId
   *   The session id or null.
   */
  public function __construct(Connector $connector, string $sessionId = NULL) {
    parent::__construct($connector);

    if ($sessionId !== NULL) {
      $this->setLocation(sprintf('%s/%s', self::$path, $sessionId));
      $this[static::ID_FIELD] = $sessionId;
    }
  }

  /**
   * Creates the session.
   *
   * @param array $data
   *   The data.
   *
   * @return $this
   *   The self.
   */
  public function create(array $data) {
    $data = $this->post(self::$path, $data)
      ->status('200')
      ->contentType('application/json')
      ->getJson();
    $this->exchangeArray($data);

    $this->setLocation(sprintf('%s/%s', self::$path, $this[static::ID_FIELD]));

    return $this;
  }

  /**
   * Updates the session.
   *
   * @param array $data
   *   The data to update.
   *
   * @return $this
   *   The self.
   */
  public function update(array $data) {
    $this->post($this->getLocation(), $data)
      ->status('204');

    return $this;
  }

}
