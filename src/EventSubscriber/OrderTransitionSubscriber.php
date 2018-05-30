<?php

namespace Drupal\commerce_klarna_payments\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles order capturing when the order is placed.
 */
class OrderTransitionSubscriber implements EventSubscriberInterface {


  /**
   * Constructs a new instance.
   */
  public function __construct() {
  }

  /**
   * This method is called whenever the onOrderPlace event is dispatched.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events['onOrderPlace'] = ['onOrderPlace'];

    return $events;
  }

}
