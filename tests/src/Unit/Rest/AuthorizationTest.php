<?php

namespace Drupal\Tests\commerce_klarna_payments\Unit\Rest;

use Drupal\commerce_klarna_payments\Klarna\Rest\Authorization;

/**
 * Product identifier request unit tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Klarna\Rest\Authorization
 */
class AuthorizationTest extends TestBase {

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $authorization = new Authorization($this->connector, 'non-existent-token');
    $this->assertEquals('/payments/v1/authorizations/non-existent-token', $authorization->getLocation());
  }

  /**
   * @covers ::getId
   */
  public function testGetId() {
    $authorization = new Authorization($this->connector, 'token');
    $this->assertEquals('token', $authorization->getId());
  }

  /**
   * @covers ::createToken
   */
  public function testCreateToken() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/authorizations/test/customer-token',
        'POST',
        ['Content-Type' => 'application/json'],
        json_encode($data)
      )
      ->will($this->returnValue($this->request));

    $this->connector->expects($this->once())
      ->method('send')
      ->with($this->request)
      ->will($this->returnValue($this->response));

    $this->response->expects($this->once())
      ->method('getStatusCode')
      ->will($this->returnValue('200'));

    $this->response->expects($this->once())
      ->method('hasHeader')
      ->with('Content-Type')
      ->will($this->returnValue(TRUE));

    $this->response->expects($this->once())
      ->method('getHeader')
      ->will($this->returnValue(['application/json']));

    $expected = [
      'test_data' => 'data',
    ];

    $this->response->expects($this->once())
      ->method('getBody')
      ->will($this->returnValue(\GuzzleHttp\json_encode($expected)));

    $authorization = new Authorization($this->connector, 'test');
    $authorization->createToken($data);

    $this->assertEquals($expected, (array) $authorization);
  }

  /**
   * Make sure that an unknown status code response results in an exception.
   *
   * @covers ::createToken
   * @expectedException \RuntimeException
   * @expectedExceptionMessage Unexpected response status code: 204
   */
  public function testCreateTokenInvalidStatusCode() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/authorizations/test/customer-token',
        'POST',
        ['Content-Type' => 'application/json'],
        json_encode($data)
      )
      ->will($this->returnValue($this->request));

    $this->connector->expects($this->once())
      ->method('send')
      ->with($this->request)
      ->will($this->returnValue($this->response));

    $this->response->expects($this->once())
      ->method('getStatusCode')
      ->will($this->returnValue('204'));

    $authorization = new Authorization($this->connector, 'test');
    $authorization->createToken($data);
  }

  /**
   * Make sure a non-JSON response results in an exception.
   *
   * @covers ::createToken
   * @expectedException \RuntimeException
   * @expectedExceptionMessage Unexpected Content-Type header received: text/plain
   */
  public function testCreateTokenNotJson() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/authorizations/test/customer-token',
        'POST',
        ['Content-Type' => 'application/json'],
        json_encode($data)
      )
      ->will($this->returnValue($this->request));

    $this->connector->expects($this->once())
      ->method('send')
      ->with($this->request)
      ->will($this->returnValue($this->response));

    $this->response->expects($this->once())
      ->method('getStatusCode')
      ->will($this->returnValue('200'));

    $this->response->expects($this->once())
      ->method('hasHeader')
      ->with('Content-Type')
      ->will($this->returnValue(TRUE));

    $this->response->expects($this->once())
      ->method('getHeader')
      ->with('Content-Type')
      ->will($this->returnValue(['text/plain']));

    $authorization = new Authorization($this->connector, 'test');
    $authorization->createToken($data);
  }

  /**
   * Make sure we can create orders.
   *
   * @covers ::create
   */
  public function testCreate() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/authorizations/test/order',
        'POST',
        ['Content-Type' => 'application/json'],
        json_encode($data)
      )
      ->will($this->returnValue($this->request));

    $this->connector->expects($this->once())
      ->method('send')
      ->with($this->request)
      ->will($this->returnValue($this->response));

    $this->response->expects($this->once())
      ->method('getStatusCode')
      ->will($this->returnValue('200'));

    $this->response->expects($this->once())
      ->method('hasHeader')
      ->with('Content-Type')
      ->will($this->returnValue(TRUE));

    $this->response->expects($this->once())
      ->method('getHeader')
      ->will($this->returnValue(['application/json']));

    $expected = [
      'order_id' => 'test',
    ];

    $this->response->expects($this->once())
      ->method('getBody')
      ->will($this->returnValue(\GuzzleHttp\json_encode($expected)));

    $authorization = new Authorization($this->connector, 'test');
    $authorization->create($data);

    $this->assertEquals($expected, (array) $authorization);
  }

  /**
   * Make sure that an unknown status code response results in an exception.
   *
   * @covers ::create
   * @expectedException \RuntimeException
   * @expectedExceptionMessage Unexpected response status code: 204
   */
  public function testCreateInvalidStatusCode() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/authorizations/test/order',
        'POST',
        ['Content-Type' => 'application/json'],
        json_encode($data)
      )
      ->will($this->returnValue($this->request));

    $this->connector->expects($this->once())
      ->method('send')
      ->with($this->request)
      ->will($this->returnValue($this->response));

    $this->response->expects($this->once())
      ->method('getStatusCode')
      ->will($this->returnValue('204'));

    $authorization = new Authorization($this->connector, 'test');
    $authorization->create($data);
  }

  /**
   * Make sure a non-JSON response results in an exception.
   *
   * @covers ::create
   * @expectedException \RuntimeException
   * @expectedExceptionMessage Unexpected Content-Type header received: text/plain
   */
  public function testCreateNotJson() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/authorizations/test/order',
        'POST',
        ['Content-Type' => 'application/json'],
        json_encode($data)
      )
      ->will($this->returnValue($this->request));

    $this->connector->expects($this->once())
      ->method('send')
      ->with($this->request)
      ->will($this->returnValue($this->response));

    $this->response->expects($this->once())
      ->method('getStatusCode')
      ->will($this->returnValue('200'));

    $this->response->expects($this->once())
      ->method('hasHeader')
      ->with('Content-Type')
      ->will($this->returnValue(TRUE));

    $this->response->expects($this->once())
      ->method('getHeader')
      ->with('Content-Type')
      ->will($this->returnValue(['text/plain']));

    $authorization = new Authorization($this->connector, 'test');
    $authorization->create($data);
  }

}
