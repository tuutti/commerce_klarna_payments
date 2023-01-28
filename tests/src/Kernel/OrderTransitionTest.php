<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\ObjectSerializerTrait;
use Drupal\commerce_klarna_payments\PaymentGatewayPluginTrait;
use Drupal\commerce_order\Entity\Order as OrderEntity;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\commerce_price\Price;
use GuzzleHttp\Psr7\Response;
use Klarna\OrderManagement\Model\Capture;
use Klarna\OrderManagement\Model\Order;

/**
 * Request builder tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\EventSubscriber\OrderTransitionSubscriber
 */
class OrderTransitionTest extends KlarnaKernelBase {

  use ObjectSerializerTrait;
  use PaymentGatewayPluginTrait;

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  private ?PaymentStorageInterface $paymentStorage;

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();

    $this->paymentStorage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');

    OrderType::load('default')
      ->setWorkflowId('order_fulfillment_validation')
      ->save();
  }

  /**
   * Populates API manager service with mock http client.
   *
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   *   The responses.
   */
  private function populateHttpClient(array $responses) : void {
    $apiManager = new ApiManager(
      $this->container->get('event_dispatcher'),
      $this->container->get('commerce_klarna_payments.request_builder'),
      $this->createMockHttpClient($responses)
    );

    $this->container->set('commerce_klarna_payments.api_manager', $apiManager);
    $this->refreshServices();
  }

  /**
   * Asserts the order.
   *
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   *   The responses.
   * @param callable $callback
   *   The callback.
   */
  private function assertOrder(array $responses, callable $callback) : void {
    $order = $this->createOrder([], ['klarna_order_id' => '123'], new Price('10', 'USD'));
    $this->populateHttpClient($responses);

    // Manually create the payment made in Klarna::onReturn() callback.
    $this->getPlugin($order)->getOrCreatePayment($order);

    foreach (['place', 'validate', 'fulfill'] as $state) {
      $order = OrderEntity::load($order->id());
      $order->getState()->applyTransitionById($state);
      $order->save();
    }

    $callback($order);

    // Commerce payment's order update destruct event is never run after
    // the payment is completed, making order never paid in full.
    // Make sure order refresh service collector is run.
    $order->setRefreshState(OrderInterface::REFRESH_ON_LOAD);
    $order->save();
    $order = $this->reloadEntity($order);
    $this->assertTrue($order->isPaid());
    $this->assertEquals('completed', $order->getState()->getId());
  }

  /**
   * Asserts that the give captures exist.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface[] $payments
   *   The payments.
   * @param string[] $captures
   *   The capture ids.
   */
  private function assertCaptures(array $payments, array $captures) : void {
    foreach ($captures as $cid) {
      $found = array_filter($payments, function (PaymentInterface $payment) use ($cid) {
        return $cid === $payment->getRemoteId();
      });

      $this->assertCount(1, $found);
    }
  }

  /**
   * Tests that validating order doesn't trigger onOrderPlace().
   */
  public function testInvalidState() : void {
    $order = $this->createOrder();

    $order->getState()->applyTransitionById('place');
    $order->getState()->applyTransitionById('validate');
    $order->save();
    $this->assertTrue($order->getTotalPaid()->isZero());
  }

  /**
   * Tests that order gets marked as paid.
   */
  public function testOnOrderPlacePaid() : void {
    $responses = [
      // Skip OrderTransitionSubscriber::updateOrderNumberOnPlace().
      new Response(404),
      // getOrder inside OrderTransitionSubscriber::onOrderPlace.
      new Response(200, [], $this->getFixture('get-order-no-captures.json')),
      // getOrder inside ApiManager::createCapture.
      new Response(200, [], $this->getFixture('get-order-no-captures.json')),
      // createCapture inside ApiManager::createCapture.
      new Response(200),
      // getOrder inside ApiManager::createCapture.
      new Response(200, [], $this->getFixture('get-order.json')),
    ];

    $this->assertOrder($responses, function (OrderInterface $order) {
      $payments = $this->paymentStorage->loadMultipleByOrder($order);
      $this->assertCount(1, $payments);
      $this->assertEquals('4ba29b50-be7b-44f5-a492-113e6a865e22', reset($payments)->getRemoteId());
    });
  }

  /**
   * Ensure orders that are fully captured via merchant panel can be completed.
   */
  public function testOnOrderPlaceFullyCaptured() : void {
    $captures = [
      new Capture([
        'capture_id' => '4ba29b50-be7b-44f5-a492-113e6a865e22',
        'captured_amount' => 1000,
      ]),
    ];
    /** @var \Klarna\OrderManagement\Model\Order $orderResponse */
    $orderResponse = $this->jsonToModel($this->getFixture('get-order-no-captures.json'), Order::class);
    $orderResponse->setStatus(Order::STATUS_CAPTURED);
    $orderResponse->setCaptures($captures);
    $orderResponse->setCapturedAmount(1000);

    $responses = [
      // Skip OrderTransitionSubscriber::updateOrderNumberOnPlace().
      new Response(404),
      // getOrder inside OrderTransitionSubscriber::onOrderPlace.
      new Response(200, [], $this->modelToJson($orderResponse)),
      // getOrder inside ApiManager::getOrder.
      new Response(200, [], $this->modelToJson($orderResponse)),
    ];

    $this->assertOrder($responses, function (OrderInterface $order) {
      $payments = $this->paymentStorage->loadMultipleByOrder($order);
      $this->assertCount(1, $payments);

      $captures = ['4ba29b50-be7b-44f5-a492-113e6a865e22'];
      $this->assertCaptures($payments, $captures);
    });
  }

}
