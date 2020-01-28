<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gathers the session data for Klarna order.
 */
final class RequestSubscriber implements EventSubscriberInterface {

  /**
   * The request builder.
   *
   * @var \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder
   */
  private $builder;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder $builder
   *   The request builder.
   */
  public function __construct(RequestBuilder $builder) {
    $this->builder = $builder;
  }

  /**
   * Responds to order create event.
   *
   * @param \Drupal\commerce_klarna_payments\Event\RequestEvent $event
   *   The event to respond to.
   */
  public function onOrderCreate(RequestEvent $event) {
    $order = $this
      ->builder
      ->createPlaceRequest($event->getData(), $event->getOrder());

    $event->setData($order);
  }

  /**
   * Responds to session create event.
   *
   * @param \Drupal\commerce_klarna_payments\Event\RequestEvent $event
   *   The event to respond to.
   */
  public function onSessionCreate(RequestEvent $event) {
    $session = $this
      ->builder
      ->createUpdateRequest($event->getData(), $event->getOrder());

    $event->setData($session);
  }

  /**
   * Responds to capture create event.
   *
   * @param \Drupal\commerce_klarna_payments\Event\RequestEvent $event
   *   The event to respond to.
   */
  public function onCaptureCreate(RequestEvent $event) {
    $session = $this
      ->builder
      ->createCaptureRequest($event->getData(), $event->getOrder());

    $event->setData($session);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[Events::SESSION_CREATE] = ['onSessionCreate'];
    $events[Events::ORDER_CREATE] = ['onOrderCreate'];
    $events[Events::CAPTURE_CREATE] = ['onCaptureCreate'];
    return $events;
  }

}
