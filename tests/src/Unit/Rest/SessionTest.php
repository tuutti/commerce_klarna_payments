<?php

namespace Drupal\Tests\commerce_klarna_payments\Unit\Rest;

use Drupal\commerce_klarna_payments\Klarna\Rest\Session;

/**
 * Product identifier request unit tests.
 *
 * @group commerce_klarna_payments
 * @coversDefaultClass \Drupal\commerce_klarna_payments\Klarna\Rest\Session
 */
class SessionTest extends TestBase {

  /**
   * @covers ::__construct
   */
  public function testConstructorNew() {
    $session = new Session($this->connector);
    $this->assertEquals(NULL, $session->getLocation());
  }

  /**
   * @covers ::__construct
   * @covers ::getId
   */
  public function testConstructorExisting() {
    $session = new Session($this->connector, '123');
    $this->assertEquals('/payments/v1/sessions/123', $session->getLocation());
    $this->assertEquals('123', $session->getId());
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/sessions',
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
      'session_id' => 'test',
    ];

    $this->response->expects($this->once())
      ->method('getBody')
      ->will($this->returnValue(\GuzzleHttp\json_encode($expected)));

    $session = new Session($this->connector);
    $session->create($data);

    $this->assertEquals((array) $session, $expected);
    $this->assertEquals('test', $session->getId());
    $this->assertEquals('/payments/v1/sessions/test', $session->getLocation());
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
        '/payments/v1/sessions',
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

    $session = new Session($this->connector);
    $session->create($data);
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
        '/payments/v1/sessions',
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

    $session = new Session($this->connector);
    $session->create($data);
  }

  /**
   * @covers ::update
   */
  public function testUpdate() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/sessions/test',
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

    $session = new Session($this->connector, 'test');
    $session->update($data);
  }

  /**
   * @covers ::update
   * @expectedException \RuntimeException
   * @expectedExceptionMessage Unexpected response status code: 200
   */
  public function testUpdateInvalidStatusCode() {
    $data = ['purchase_currency' => 'EUR'];

    $this->connector->expects($this->once())
      ->method('createRequest')
      ->with(
        '/payments/v1/sessions/test',
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

    $session = new Session($this->connector, 'test');
    $session->update($data);
  }

}
