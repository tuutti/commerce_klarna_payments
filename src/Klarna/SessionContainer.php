<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna;

use Drupal\commerce_klarna_payments\Event\SessionEvent;

/**
 * Provides a value object to store session data.
 */
final class SessionContainer {

  protected $data;

  public function __construct(SessionEvent $event, string $token) {
    $this->data = $event->getData();
    $this->setToken($token);
  }

  public function setToken(string $token) : self {
    $this->data['client_token'] = $token;

    return $this;
  }

  public function toArray() : array {
    return $this->data;
  }

}
