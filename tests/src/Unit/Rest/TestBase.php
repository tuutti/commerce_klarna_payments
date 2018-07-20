<?php

namespace Drupal\Tests\commerce_klarna_payments\Unit\Rest;

use Drupal\Tests\UnitTestCase;
use Klarna\Rest\Transport\Connector;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Test base for rest resources.
 */
abstract class TestBase extends UnitTestCase {

  protected $response;
  protected $request;
  protected $connector;

  /**
   * Sets up the test fixtures.
   */
  protected function setUp() {
    parent::setUp();

    $this->request = $this->getMockBuilder(RequestInterface::class)
      ->getMock();
    $this->response = $this->getMockBuilder(ResponseInterface::class)
      ->getMock();
    $this->connector = $this->getMockBuilder(Connector::class)
      ->disableOriginalConstructor()
      ->getMock();
  }

}
