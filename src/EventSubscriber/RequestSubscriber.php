<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\Klarna\Service\Payment\RequestBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use KlarnaPayments\Data\Payment\Session\Session;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gathers the session data for Klarna order.
 */
final class RequestSubscriber implements EventSubscriberInterface {

  protected $builder;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_klarna_payments\Klarna\Service\Payment\RequestBuilder $builder
   *   The request builder.
   */
  public function __construct(RequestBuilder $builder) {
    $this->builder = $builder;
  }

  /**
   * Builds request for given type and order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $type
   *   The type.
   *
   * @return \KlarnaPayments\Data\Payment\Session\Session
   *   The request.
   */
  private function buildObject(OrderInterface $order, string $type) : Session {
    $session = $this->builder
      ->withOrder($order)
      ->createObject($type);

    return $session;
  }

  /**
   * Responds to RequestEvent.
   *
   * @param \Drupal\commerce_klarna_payments\Event\RequestEvent $event
   *   The event to respond to.
   */
  public function onCaptureCreate(RequestEvent $event) {
    /*if (!$event->getRequest() instanceof CreateCaptureInterface) {
      $request = $this->buildObject($event->getOrder(), 'capture');

      $event->setRequest($request);
    }*/
  }

  /**
   * Responds to RequestEvent.
   *
   * @param \Drupal\commerce_klarna_payments\Event\RequestEvent $event
   *   The event to respond to.
   */
  public function onOrderCreate(RequestEvent $event) {
    if (!$event->getObject() instanceof Session) {
      $session = $this->buildObject($event->getOrder(), 'place');

      $event->setObject($session);
    }
  }

  /**
   * Responds to RequestEvent.
   *
   * @param \Drupal\commerce_klarna_payments\Event\RequestEvent $event
   *   The event to respond to.
   */
  public function onSessionCreate(RequestEvent $event) {
    if (!$event->getObject() instanceof Session) {
      $session = $this->buildObject($event->getOrder(), 'create');

      $event->setObject($session);
    }
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
