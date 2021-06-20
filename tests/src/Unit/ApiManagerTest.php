<?php

declare(strict_types = 1);

namespace Drupal\Tests\commerce_klarna_payments\Unit;

use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\Event\RequestEvent;
use Drupal\commerce_klarna_payments\Exception\NonKlarnaOrderException;
use Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\KlarnaInterface;
use Drupal\commerce_klarna_payments\Request\Payment\RequestBuilder;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface as PaymentGatewayPluginInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Klarna\Configuration;
use Klarna\OrderManagement\Model\Capture;
use Klarna\Model\ModelInterface;
use Klarna\OrderManagement\Model\Order;
use Klarna\Payments\Model\Order as PaymentOrder;
use Klarna\Payments\Model\Session;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\Prophecy\ProphecyInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * ApiManager tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\ApiManager
 */
class ApiManagerTest extends FixtureUnitTestBase {

  /**
   * Creates HTTP client stub.
   *
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   The expected responses.
   *
   * @return \GuzzleHttp\Client
   *   The client.
   */
  private function createHttpClient(array $responses) : Client {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);

    return new Client(['handler' => $handlerStack]);
  }

  /**
   * Creates new api manager instance.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Klarna\Model\ModelInterface|null $model
   *   The model.
   * @param \GuzzleHttp\Client|null $client
   *   The client.
   *
   * @return \Drupal\commerce_klarna_payments\ApiManager
   *   The api manager instance.
   */
  private function createSut(OrderInterface $order, ModelInterface $model = NULL, Client $client = NULL) : ApiManager {
    $requestBuilder = $this->prophesize(RequestBuilder::class);

    if ($model instanceof Session) {
      $requestBuilder->createSessionRequest($order)->willReturn($model);
    }
    if ($model instanceof Capture) {
      $requestBuilder->createCaptureRequest($order)->willReturn($model);
    }
    $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
    $eventDispatcher->dispatch(Argument::any(), Argument::any())
      ->willReturn(new RequestEvent($order, $model));

    return new ApiManager($eventDispatcher->reveal(), $requestBuilder->reveal(), $client);
  }

  /**
   * Populates working plugin to the given order.
   *
   * @param \Prophecy\Prophecy\ObjectProphecy $order
   *   The order to populate the stub to.
   */
  private function populateOrderPluginStub(ObjectProphecy $order) : void {
    $plugin = $this->prophesize(KlarnaInterface::class);
    $plugin->getClientConfiguration()->willReturn(new Configuration());

    $gateway = $this->prophesize(PaymentGatewayInterface::class);
    $gateway->getPlugin()->willReturn($plugin->reveal());

    $list = $this->prophesize(FieldItemListInterface::class);
    $list->isEmpty()->willReturn(FALSE);
    $list->first()->willReturn((object) ['entity' => $gateway->reveal()]);

    $order->get('payment_gateway')->willReturn($list->reveal());
  }

  /**
   * Creates an order stub.
   *
   * @param mixed $returnValue
   *   The expected return value.
   *
   * @return \Prophecy\Prophecy\ProphecyInterface
   *   The prophecy object.
   */
  private function createOrderStub($returnValue) : ProphecyInterface {
    $order = $this->prophesize(OrderInterface::class);
    $order->getData('klarna_order_id')
      ->shouldBeCalled()
      ->willReturn($returnValue);
    return $order;
  }

  /**
   * Tests getPlugin() when gateway is not set.
   *
   * @covers ::getPlugin
   */
  public function testGetPluginGatewayNotFound() {
    $this->expectException(\InvalidArgumentException::class);

    $list = $this->prophesize(FieldItemInterface::class);
    $list->isEmpty()->willReturn(TRUE);

    $order = $this->prophesize(OrderInterface::class);
    $order->get('payment_gateway')->willReturn($list->reveal());

    $sut = $this->createSut($order->reveal());
    $sut->getPlugin($order->reveal());
  }

  /**
   * Tests getPlugin() for non-klarna orders.
   *
   * @covers ::getPlugin
   */
  public function testGetPluginNonKlarnaOrder() {
    $this->expectException(NonKlarnaOrderException::class);

    $plugin = $this->prophesize(PaymentGatewayPluginInterface::class);
    $gateway = $this->prophesize(PaymentGatewayInterface::class);
    $gateway->getPlugin()->willReturn($plugin->reveal());

    $list = $this->prophesize(FieldItemListInterface::class);
    $list->isEmpty()->willReturn(FALSE);
    $list->first()->willReturn((object) ['entity' => $gateway->reveal()]);

    $order = $this->prophesize(OrderInterface::class);
    $order->get('payment_gateway')->willReturn($list->reveal());

    $sut = $this->createSut($order->reveal());
    $sut->getPlugin($order->reveal());
  }

  /**
   * Tests getPlugin() with valid data.
   *
   * @covers ::getPlugin
   */
  public function testGetPlugin() : void {
    $order = $this->prophesize(OrderInterface::class);
    $this->populateOrderPluginStub($order);

    $sut = $this->createSut($order->reveal());
    $plugin = $sut->getPlugin($order->reveal());

    $this->assertInstanceOf(KlarnaInterface::class, $plugin);
  }

  /**
   * Make sure we cannot override Session object with invalid one.
   *
   * @covers ::sessionRequest
   * @covers ::getSessionsApi
   * @covers ::getPlugin
   */
  public function testSessionRequestInvalidArgumentException() : void {
    $this->expectException(\InvalidArgumentException::class);
    $order = $this->prophesize(OrderInterface::class)->reveal();

    $requestBuilder = $this->prophesize(RequestBuilder::class);
    $requestBuilder->createSessionRequest($order)->willReturn(new Session());

    // Override the session via event dispatcher event.
    $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
    $eventDispatcher->dispatch(Argument::any(), Argument::any())
      ->willReturn(new RequestEvent($order, new Order()));

    $sut = new ApiManager($eventDispatcher->reveal(), $requestBuilder->reveal());
    $sut->sessionRequest($order);
  }

  /**
   * Tests sessionRequest() with empty session id.
   *
   * @covers ::sessionRequest
   * @covers ::getSessionsApi
   * @covers ::getPlugin
   */
  public function testSessionRequestWithEmptySessionId() : void {
    // Make sure we call createCreditSession(), save the session id
    // and re-read the session with readCreditSession().
    $order = $this->prophesize(OrderInterface::class);
    $this->populateOrderPluginStub($order);
    $order->setData('klarna_session_id', Argument::any())
      ->shouldBeCalled()
      ->willReturn($order->reveal());
    $order->getData('klarna_session_id')
      ->shouldBeCalled()
      ->willReturn(NULL);
    $order->save()
      ->shouldBeCalled()
      ->willReturn(1);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('create-credit-session.json')),
      new Response(200, [], $this->getFixture('create-credit-session.json')),
    ]);

    $sut = $this->createSut($order->reveal(), new Session(), $client);
    $response = $sut->sessionRequest($order->reveal());

    $this->assertInstanceOf(Session::class, $response);
  }

  /**
   * Tests sessionRequest() with expired session id.
   *
   * @covers ::sessionRequest
   * @covers ::getSessionsApi
   * @covers ::getPlugin
   */
  public function testSessionRequestWithExpiredSessionId() : void {
    $order = $this->prophesize(OrderInterface::class);
    $this->populateOrderPluginStub($order);
    // Make sure we:
    // 1. Call ::updateCreditSession() that triggers the ApiExceptions.
    // 2. Catch the ApiException and call ::createCreditsession().
    // 3. Save the returned session ID.
    // 4. Re-read the session with ::readCreditSession().
    $order->setData('klarna_session_id', Argument::any())
      ->shouldBeCalled()
      ->willReturn($order->reveal());
    $order->getData('klarna_session_id')
      ->shouldBeCalled()
      ->willReturn('123');
    $order->save()
      ->shouldBeCalled()
      ->willReturn(1);

    $client = $this->createHttpClient([
      new Response(404),
      new Response(200, [], $this->getFixture('create-credit-session.json')),
      new Response(200, [], $this->getFixture('create-credit-session.json')),
    ]);

    $sut = $this->createSut($order->reveal(), new Session(), $client);
    $response = $sut->sessionRequest($order->reveal());

    $this->assertInstanceOf(Session::class, $response);
  }

  /**
   * Tests sessionRequest() with valid session id.
   *
   * @covers ::sessionRequest
   * @covers ::getSessionsApi
   * @covers ::getPlugin
   */
  public function testSessionRequestWithValidSessionId() : void {
    // Make sure we call ::updateCreditSession() then re-read
    // the session with ::readCreditSession().
    $order = $this->prophesize(OrderInterface::class);
    $this->populateOrderPluginStub($order);

    // We shouldn't attempt to save the session id again.
    $order->setData('klarna_session_id', Argument::any())
      ->shouldNotBeCalled();
    $order->save()
      ->shouldNotBeCalled();
    $order->getData('klarna_session_id')->willReturn('123');

    $client = $this->createHttpClient([
      new Response(200),
      new Response(200, [], $this->getFixture('create-credit-session.json')),
    ]);

    $sut = $this->createSut($order->reveal(), new Session(), $client);
    $response = $sut->sessionRequest($order->reveal());

    $this->assertInstanceOf(Session::class, $response);
  }

  /**
   * Tests authorizeOrder() with valid order data.
   *
   * @covers ::authorizeOrder
   */
  public function testAuthorizeOrder() : void {
    $order = $this->prophesize(OrderInterface::class);
    $order->setData('klarna_order_id', Argument::any())
      ->shouldBeCalled()
      ->willReturn($order->reveal());
    $order->save()
      ->shouldBeCalled()
      ->willReturn(1);

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('create-order-authorization.json')),
    ]);

    $sut = $this->createSut($order->reveal(), new Session(), $client);
    $response = $sut->authorizeOrder($order->reveal(), '123');

    $this->assertInstanceOf(PaymentOrder::class, $response);
  }

  /**
   * Tests getOrder() for non-existent order.
   *
   * @cover ::getOrder
   */
  public function testGetOrderNoOrder() : void {
    $this->expectException(NonKlarnaOrderException::class);
    $order = $this->createOrderStub(NULL);

    $this->populateOrderPluginStub($order);

    $sut = $this->createSut($order->reveal());
    $sut->getOrder($order->reveal());
  }

  /**
   * Tests getOrder().
   *
   * @covers ::getOrder
   */
  public function testGetOrder() : void {
    $order = $this->createOrderStub('123');
    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('get-order.json')),
    ]);
    $sut = $this->createSut($order->reveal(), NULL, $client);
    $response = $sut->getOrder($order->reveal());

    $this->assertInstanceOf(Order::class, $response);
  }

  /**
   * Tests acknowledgeOrder().
   *
   * @covers ::acknowledgeOrder
   */
  public function testAcknowledgeOrder() : void {
    $order = $this->createOrderStub('123');

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('get-order.json')),
      new Response(200),
      new Response(200, [], $this->getFixture('get-order.json')),
      new Response(200),
    ]);
    $sut = $this->createSut($order->reveal(), NULL, $client);
    $sut->acknowledgeOrder($order->reveal());

    $orderResponse = $sut->getOrder($order->reveal());
    $sut->acknowledgeOrder($order->reveal(), $orderResponse);
  }

  /**
   * Tests that passing a non-capture object is prohibited.
   *
   * @covers ::createCapture
   */
  public function testCreateCaptureException() : void {
    $this->expectException(\InvalidArgumentException::class);
    $order = $this->createOrderStub('123');

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('get-order.json')),
    ]);

    $requestBuilder = $this->prophesize(RequestBuilder::class);
    $requestBuilder->createCaptureRequest($order->reveal())->willReturn(new Capture());

    // Override the capture via event dispatcher event.
    $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
    $eventDispatcher->dispatch(Argument::any(), Argument::any())
      ->willReturn(new RequestEvent($order->reveal(), new Order()));

    $sut = new ApiManager($eventDispatcher->reveal(), $requestBuilder->reveal(), $client);
    $sut->createCapture($order->reveal());
  }

  /**
   * Tests create capture.
   *
   * @covers ::createCapture
   */
  public function testCreateCapture() : void {
    $order = $this->createOrderStub('123');

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      // First capture (getOrder, captureOrder, getOrder).
      new Response(200, [], $this->getFixture('get-order-no-captures.json')),
      new Response(200),
      new Response(200, [], $this->getFixture('get-order.json')),
      // Second capture (getOrder, captureOrder, getOrder).
      new Response(200, [], $this->getFixture('get-order.json')),
      new Response(200),
      new Response(200, [], $this->getFixture('get-order-two-captures.json')),
    ]);
    $sut = $this->createSut($order->reveal(), new Capture(), $client);

    $capture = $sut->createCapture($order->reveal());
    $this->assertEquals('K4MADNY-1', $capture->getKlarnaReference());

    // Make sure creating second capture returns the latest capture.
    $capture = $sut->createCapture($order->reveal());
    $this->assertEquals('K4MADNY-2', $capture->getKlarnaReference());
  }

  /**
   * Tests refundPayment().
   *
   * @covers ::refundPayment
   */
  public function testCreateRefund() : void {
    $order = $this->createOrderStub('123');

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('get-order.json')),
      new Response(200),
    ]);
    $sut = $this->createSut($order->reveal(), NULL, $client);

    $sut->refundPayment($order->reveal(), new Price('100', 'EUR'), '123');
  }

  /**
   * Tests voidPayment().
   *
   * @covers ::voidPayment
   */
  public function testVoidPayment() : void {
    $order = $this->createOrderStub('123');

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('get-order.json')),
      new Response(200),
    ]);
    $sut = $this->createSut($order->reveal(), NULL, $client);
    $sut->voidPayment($order->reveal());
  }

  /**
   * Tests releaseRemainingAuthorization().
   *
   * @covers ::releaseRemainingAuthorization
   */
  public function testReleaseRemainingAuthorization() : void {
    $order = $this->createOrderStub('123');

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('get-order.json')),
      new Response(200),
      new Response(200, [], $this->getFixture('get-order.json')),
      new Response(200),
    ]);
    $sut = $this->createSut($order->reveal(), NULL, $client);
    $sut->releaseRemainingAuthorization($order->reveal());

    $orderResponse = $sut->getOrder($order->reveal());
    $sut->releaseRemainingAuthorization($order->reveal(), $orderResponse);
  }

  /**
   * Tests handleNotifactionEvent().
   *
   * @covers ::handleNotificationEvent
   * @dataProvider handleNotificationData
   */
  public function testHandleNotificationEvent(string $event, bool $invalid = FALSE) : void {
    if ($invalid) {
      $this->expectException(\InvalidArgumentException::class);
    }
    $order = $this->createOrderStub('123');

    $this->populateOrderPluginStub($order);

    $client = $this->createHttpClient([
      new Response(200, [], $this->getFixture('get-order.json')),
    ]);
    $sut = $this->createSut($order->reveal(), new Order(), $client);
    $sut->handleNotificationEvent($order->reveal(), $event);
  }

  /**
   * Data provider for handleNotification() tests.
   *
   * @return \string[][]
   *   The test data.
   */
  public function handleNotificationData() : array {
    return [
      ['nonexistent', TRUE],
      ['FRAUD_RISK_ACCEPTED'],
      ['FRAUD_RISK_REJECTED'],
      ['FRAUD_RISK_STOPPED'],
    ];
  }

}
