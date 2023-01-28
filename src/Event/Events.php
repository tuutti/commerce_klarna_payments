<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Event;

/**
 * Available events.
 */
final class Events {

  /**
   * An event to be run when we acknowledge the order.
   */
  public const ACKNOWLEDGE_ORDER = 'commerce_klarna_payments.acknowledge_order';

  /**
   * An event to alter values before creating a new session.
   */
  public const SESSION_CREATE = 'commerce_klarna_payments.session_create';

  /**
   * An event to alter values before creating a new order.
   */
  public const ORDER_CREATE = 'commerce_klarna_payments.order_create';

  /**
   * An event to alter values before creating a payment capture.
   */
  public const CAPTURE_CREATE = 'commerce_klarna_payments.capture_create';

  /**
   * An event to be run when we release the remaining authorizations.
   */
  public const RELEASE_REMAINING_AUTHORIZATION = 'commerce_klarna_payments.release_remaining_authorization';

  /**
   * An event to be run when we void the given payment.
   */
  public const VOID_PAYMENT = 'commerce_klarna_payments.void_payment';

  /**
   * This even is run when fraud status is rejected.
   */
  public const FRAUD_NOTIFICATION_REJECTED = 'commerce_klarna_payments.fraud_notification_rejected';

  /**
   * This even is run when fraud status is accepted.
   */
  public const FRAUD_NOTIFICATION_ACCEPTED = 'commerce_klarna_payments.fraud_notification_accepted';

  /**
   * This even is run when fraud status is stopped.
   */
  public const FRAUD_NOTIFICATION_STOPPED = 'commerce_klarna_payments.fraud_notification_stopped';

  /**
   * This even is run when new refund is created.
   */
  public const REFUND_CREATE = 'commerce_klarna_payments.refund_create';

  /**
   * This event is run when the push endpoint gets called.
   */
  public const PUSH_ENDPOINT_CALLED = 'commerce_klarna_payments.push_endpoint_called';

}
