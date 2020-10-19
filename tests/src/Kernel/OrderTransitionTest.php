<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\ObjectSerializerTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentInterface;
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

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  private $paymentStorage;

  /**
   * The payment plugin.
   *
   * @var \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna
   */
  private $plugin;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
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
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   *   The responses.
   */
  private function populateHttpClient(OrderInterface $order, array $responses) : void {
    $apiManager = new ApiManager(
      $this->container->get('event_dispatcher'),
      $this->container->get('commerce_klarna_payments.request_builder'),
      $this->createMockHttpClient($responses)
    );

    $this->plugin = $apiManager->getPlugin($order)
      ->setApiManager($apiManager);

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
    // We should refresh order every time we load it.
    $order->setRefreshState(OrderInterface::REFRESH_ON_LOAD);

    $this->populateHttpClient($order, $responses);

    // Account for payment made in Klarna::onReturn() callback.
    $this->plugin->createPayment($order);

    foreach (['validate', 'fulfill'] as $state) {
      $order->getState()->applyTransitionById($state);
      $order->save();
    }

    $callback($order);

    $order = $this->reloadEntity($order);
    $this->assertTrue($order->getTotalPaid()->equals($order->getTotalPrice()));
    $this->assertEqual($order->getState()->getId(), 'completed');
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

    $order->getState()->applyTransitionById('validate');
    $order->save();
    $this->assertTrue($order->getTotalPaid()->isZero());
  }

  /**
   * Tests that order gets marked as paid.
   */
  public function testOnOrderPlace() : void {
    $responses = [
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
      $this->assertEqual('4ba29b50-be7b-44f5-a492-113e6a865e22', reset($payments)->getRemoteId());
    });
  }

  /**
   * Tests that onOrderPlace() syncs two captures made in merchant panel.
   */
  public function testOnOrderPlaceSyncRemote() : void {
    $responses = [
      // getOrder inside OrderTransitionSubscriber::onOrderPlace.
      new Response(200, [], $this->getFixture('get-order-two-captures.json')),
      // getOrder inside ApiManager::createCapture.
      new Response(200, [], $this->getFixture('get-order-two-captures.json')),
      // createCapture inside ApiManager::createCapture.
      new Response(200),
      // getOrder inside ApiManager::createCapture.
      new Response(200, [], $this->getFixture('get-order-two-captures.json')),
    ];

    $this->assertOrder($responses, function (OrderInterface $order) {
      $payments = $this->paymentStorage->loadMultipleByOrder($order);
      $this->assertCount(2, $payments);

      $captures = [
        '4ba29b50-be7b-44f5-a492-113e6a865e22',
        'be7b-44f5-a492-113e6a865e22-4ba29b50',
      ];
      $this->assertCaptures($payments, $captures);
    });
  }

  /**
   * Tests we can complete orders that are fully captured via merchant panel.
   */
  public function testOnOrderplaceFullyCaptured() : void {
    $captures = [
      new Capture([
        'capture_id' => '4ba29b50-be7b-44f5-a492-113e6a865e22',
        'captured_amount' => 1000,
      ]),
    ];
    /** @var \Klarna\Model\Order $orderResponse */
    $orderResponse = $this->jsonToModel($this->getFixture('get-order-no-captures.json'), Order::class);
    $orderResponse->setCaptures($captures);

    $responses = [
      // getOrder inside OrderTransitionSubscriber::onOrderPlace.
      new Response(200, [], $this->modelToJson($orderResponse)),
    ];

    $this->assertOrder($responses, function (OrderInterface $order) {
      $payments = $this->paymentStorage->loadMultipleByOrder($order);
      $this->assertCount(1, $payments);

      $captures = [
        '4ba29b50-be7b-44f5-a492-113e6a865e22',
      ];
      $this->assertCaptures($payments, $captures);
    });
  }

  /**
   * Tests that onOrderPlace() will complete partially captured orders.
   */
  public function testOnOrderPlacePartiallyCaptured() : void {
    $captures = [
      new Capture([
        'capture_id' => '4ba29b50-be7b-44f5-a492-113e6a865e22',
        'captured_amount' => 500,
      ]),
    ];
    /** @var \Klarna\Model\Order $orderResponse */
    $orderResponse = $this->jsonToModel($this->getFixture('get-order-no-captures.json'), Order::class);
    $orderResponse->setCaptures($captures);

    $orderResponse2 = clone $orderResponse;
    $orderResponse2->setCaptures([
      new Capture([
        'capture_id' => 'be7b-44f5-a492-113e6a865e22-4ba29b50',
        'captured_amount' => 500,
      ]),
    ] + $captures);

    $responses = [
      // getOrder inside OrderTransitionSubscriber::onOrderPlace.
      new Response(200, [], $this->modelToJson($orderResponse)),
      // getOrder inside ApiManager::createCapture.
      new Response(200, [], $this->modelToJson($orderResponse)),
      // createCapture inside ApiManager::createCapture.
      new Response(200),
      // getOrder inside ApiManager::createCapture.
      new Response(200, [], $this->modelToJson($orderResponse2)),
    ];

    $this->assertOrder($responses, function (OrderInterface $order) {
      $payments = $this->paymentStorage->loadMultipleByOrder($order);
      $this->assertCount(2, $payments);

      $captures = [
        '4ba29b50-be7b-44f5-a492-113e6a865e22',
        'be7b-44f5-a492-113e6a865e22-4ba29b50',
      ];
      $this->assertCaptures($payments, $captures);
    });
  }

  /**
   * Tests order that is out of sync, but fully captured via merchant panel.
   *
   * Out of sync order means that the order has some changes made in
   * in merchant panel (like different unit price for an item).
   */
  public function testOnOrderPlaceOrderNotInSyncCaptured() : void {
    /** @var \Klarna\Model\Order $orderResponse */
    $orderResponse = $this->jsonToModel($this->getFixture('get-order-no-captures.json'), Order::class);
    // Override order total to make order 'out of sync'.
    $orderResponse->setOrderAmount(UnitConverter::toAmount(new Price('15', 'USD')));
    // Mark order as captured so we can mark the order as paid in full.
    $orderResponse->setStatus('CAPTURED');

    $responses = [
      new Response(200, [], $this->modelToJson($orderResponse)),
    ];

    $this->assertOrder($responses, function (OrderInterface $order) {
      $payments = $this->paymentStorage->loadMultipleByOrder($order);
      $this->assertCount(1, $payments);
      $this->assertNull(reset($payments)->getRemoteId());
    });

  }

  /**
   * Tests order that is out of sync, but not yet fully captured.
   */
  public function testOnOrderPlaceOrderNotInSync() : void {
    $order = $this->createOrder([], ['klarna_order_id' => '123'], new Price('10', 'USD'));
    $order->setRefreshState(OrderInterface::REFRESH_ON_LOAD);

    // Trying to fulfill out of sync order that is not yet fully captured should
    // result in PaymentGatewayException, but EntityStorage seems to catch the
    // exception and throw EntityStorageException instead.
    $this->expectExceptionMessage(sprintf('Order (%s) is out of sync, but not fully captured yet.', $order->id()));

    /** @var \Klarna\Model\Order $orderResponse */
    $orderResponse = $this->jsonToModel($this->getFixture('get-order-no-captures.json'), Order::class);
    // Override order total to make order 'out of sync'.
    $orderResponse->setOrderAmount(UnitConverter::toAmount(new Price('15', 'USD')));

    $responses = [
      new Response(200, [], $this->modelToJson($orderResponse)),
    ];

    $this->populateHttpClient($order, $responses);

    // Account for payment made in Klarna::onReturn() callback.
    $this->plugin->createPayment($order);

    $payments = $this->paymentStorage->loadMultipleByOrder($order);
    $this->assertCount(1, $payments);
    $this->assertEqual('authorization', reset($payments)->getState()->getId());

    foreach (['validate', 'fulfill'] as $state) {
      $order->getState()->applyTransitionById($state);
      $order->save();
    }
  }

}
