<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Klarna;

use Drupal\commerce_klarna_payments\Klarna\Payment\Authorization;

class AuthorizationResponse {

  protected $orderId;
  protected $redirectUrl;
  protected $fraudStatus;

  public function __construct(string $orderId, string $redirectUrl, string $fraudStatus) {
    $this->orderId = $orderId;
    $this->redirectUrl = $redirectUrl;
    $this->fraudStatus = $fraudStatus;
  }

  public static function createFromRequest(Authorization $authorization) : self {
    return new static($authorization['order_id'], $authorization['redirect_url'], $authorization['fraud_status']);
  }

  public function getOrderId() : string {
    return $this->orderId;
  }

  public function getRedirectUrl() : string {
    return $this->redirectUrl;
  }

  public function isFraud() : bool {
    return $this->fraudStatus === 'REJECTED';
  }

  public function isPending() : bool {
    return $this->fraudStatus === 'PENDING';
  }

  public function isValid() : bool {
    return $this->fraudStatus === 'ACCEPTED';
  }

}
