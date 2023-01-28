<?php

namespace Drupal\Tests\commerce_klarna_payments\Kernel;

use Drupal\commerce_klarna_payments\ObjectSerializerTrait;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\Tests\commerce_klarna_payments\Traits\OrderTestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/**
 * Kernel test base for Klarna payments.
 */
abstract class KlarnaKernelBase extends EntityKernelTestBase {

  use ObjectSerializerTrait;
  use StoreCreationTrait;
  use OrderTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'profile',
    'path',
    'address',
    'datetime',
    'entity',
    'options',
    'inline_entity_form',
    'views',
    'path',
    'path_alias',
    'state_machine',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_product',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_payment',
    'commerce_checkout',
    'commerce_klarna_payments',
  ];

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGateway
   */
  protected $gateway;

  /**
   * The commerce store.
   *
   * @var \Drupal\commerce_store\Entity\StoreInterface
   */
  protected $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->config('system.date')->set('country', ['default' => 'FI'])->save();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installConfig([
      'commerce_store',
      'commerce_product',
      'commerce_order',
      'commerce_checkout',
      'commerce_payment',
    ]);
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $currency_importer = $this->container->get('commerce_price.currency_importer');
    $currency_importer->import('USD');

    // An order item type that doesn't need a purchasable entity.
    OrderItemType::create([
      'id' => 'test',
      'label' => 'Test',
      'orderType' => 'default',
    ])->save();

    $this->gateway = PaymentGateway::create([
      'id' => 'klarna_payments',
      'label' => 'Klarna',
      'plugin' => 'klarna_payments',
    ]);
    $this->gateway->save();

    $this->store = $this->createStore('Default store', 'admin@example.com', 'online', TRUE, 'FI', 'EUR');
  }

  /**
   * Gets the fixture.
   *
   * @param string $name
   *   The name of the fixture.
   *
   * @return string
   *   The fixture.
   */
  protected function getFixture(string $name) : string {
    return file_get_contents(__DIR__ . '/../../fixtures/' . $name);
  }

  /**
   * Creates HTTP client stub.
   *
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   *   The expected responses.
   *
   * @return \GuzzleHttp\Client
   *   The client.
   */
  protected function createMockHttpClient(array $responses) : Client {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);

    return new Client(['handler' => $handlerStack]);
  }

}
