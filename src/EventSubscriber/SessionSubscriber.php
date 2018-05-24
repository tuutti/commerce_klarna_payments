<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\commerce_klarna_payments\Event\Events;
use Drupal\commerce_klarna_payments\Event\SessionEvent;
use Drupal\commerce_klarna_payments\Klarna\Data\RequestInterface;
use Drupal\commerce_klarna_payments\Klarna\Service\RequestBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gathers the session data for Klarna order.
 */
final class SessionSubscriber implements EventSubscriberInterface {

  protected $builder;

  public function __construct(RequestBuilder $builder) {
    $this->builder = $builder;
  }

  /**
   * Responds to SessionEvent.
   *
   * @param \Drupal\commerce_klarna_payments\Event\SessionEvent $event
   *   The event to respond to.
   */
  public function onSessionCreate(SessionEvent $event) {
    if ($event->getRequest() instanceof  RequestInterface) {
      // Build request.
      $request = $this->builder
        ->withOrder($event->getOrder())
        ->generateRequest('create');

      $event->setRequest($request);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[Events::SESSION_CREATE] = ['onSessionCreate'];
    return $events;
  }

}
