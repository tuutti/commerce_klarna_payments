<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\Bridge\UnitConverter;
use Drupal\commerce_klarna_payments\Controller\PushEndpointController;
use Drupal\commerce_klarna_payments\ObjectSerializerTrait;
use Drupal\commerce_order\Entity\Order as OrderEntity;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use GuzzleHttp\Psr7\Response;
use Klarna\OrderManagement\Model\Capture;
use Klarna\OrderManagement\Model\Order;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Push endpoint tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Controller\PushEndpointController
 */
class PushEndpointControllerTest extends KlarnaKernelBase {

  /**
   * The payment storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|mixed|object
   */
  protected $paymentStorage;

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();

    $this->paymentStorage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
  }

  /**
   * Test invalid requests.
   */
  public function testInvalidRequests() {
    /** @var \Klarna\OrderManagement\Model\Order $orderResponse */
    $orderResponse = $this->jsonToModel($this->getFixture('get-order.json'), Order::class);
    $order = $this->createOrder([], ['klarna_order_id' => $orderResponse->getOrderId()]);
    $orderResponse->setStatus('CANCELLED');

    $gateway = $this->gateway;
    $request = new Request();

    // No order id parameter.
    try {
      $controller = $this->createController();
      $controller->handleRequest($order, $gateway, $request);
    }
    catch (\Exception $e) {
      $this->assertTrue($e instanceof AccessDeniedHttpException);
      $this->assertEquals('Required parameters missing.', $e->getMessage());
    }

    // Wrong status.
    $request->query->set('klarna_order_id', $orderResponse->getOrderId());
    $responses = [
      new Response(200, [], $this->modelToJson($orderResponse)),
    ];
    $this->populateHttpClient($order, $responses);

    try {
      $controller = $this->createController();
      $controller->handleRequest($order, $gateway, $request);
    }
    catch (\Exception $e) {
      $this->assertTrue($e instanceof AccessDeniedHttpException);
      $this->assertEquals('Order is in invalid state.', $e->getMessage());
    }

    // Order already paid.
    $order->setTotalPaid($order->getBalance());
    $orderResponse->setStatus('CAPTURED');
    $responses = [
      new Response(200, [], $this->modelToJson($orderResponse)),
    ];
    $this->populateHttpClient($order, $responses);

    try {
      $controller = $this->createController();
      $controller->handleRequest($order, $gateway, $request);
    }
    catch (\Exception $e) {
      $this->assertTrue($e instanceof AccessDeniedHttpException);
      $this->assertEquals('Already paid.', $e->getMessage());
    }
  }

  public function testValidRequests() {
    /** @var \Klarna\OrderManagement\Model\Order $orderResponse */
    $orderResponse = $this->jsonToModel($this->getFixture('get-order.json'), Order::class);
    $order = $this->createOrder([], ['klarna_order_id' => $orderResponse->getOrderId()]);

    $gateway = $this->gateway;
    $request = new Request();
    $request->query->set('klarna_order_id', $orderResponse->getOrderId());

    $responses = [
      // Load the order.
      new Response(200, [], $this->modelToJson($orderResponse)),
      // Acknowledge the order.
      new Response(200, [], ''),
    ];
    $this->populateHttpClient($order, $responses);

    $controller = $this->createController();
    $response = $controller->handleRequest($order, $gateway, $request);
    $this->assertEquals('Ok', $response->getContent());

    /** @var PaymentInterface[] $payments */
    $payments = $this->paymentStorage->loadByProperties([
      'payment_gateway' => $gateway->id(),
      'order_id' => $order->id(),
      ]);
    $this->assertNotEmpty($payments);
    $this->assertCount(1, $payments);
    $payment = reset($payments);
    $this->assertEquals($order->getBalance()->getNumber(), (int) $payment->getAmount()->getNumber());
  }

  /**
   * Create the controller.
   *
   * @return \Drupal\commerce_klarna_payments\Controller\PushEndpointController
   * @throws \Exception
   */
  protected function createController(): PushEndpointController {
    return new PushEndpointController(
      $this->container->get('commerce_checkout.checkout_order_manager'),
      $this->container->get('commerce_klarna_payments.api_manager'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.channel.commerce_klarna_payments'),
      $this->container->get('event_dispatcher')
    );
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

}
