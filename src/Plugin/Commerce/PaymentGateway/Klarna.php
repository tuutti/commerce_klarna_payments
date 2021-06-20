<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_klarna_payments\ApiManager;
use Drupal\commerce_klarna_payments\OptionsHelper;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Klarna\Configuration;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the Klarna payments payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "klarna_payments",
 *   label = "Klarna Payments",
 *   display_label = "Klarna Payments",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_klarna_payments\PluginForm\OffsiteRedirect\KlarnaOffsiteForm",
 *   },
 *   requires_billing_information = FALSE
 * )
 */
final class Klarna extends OffsitePaymentGatewayBase implements SupportsAuthorizationsInterface, SupportsNotificationsInterface, SupportsRefundsInterface, KlarnaInterface {

  use OptionsHelper;

  /**
   * The klarna api manager.
   *
   * @var \Drupal\commerce_klarna_payments\ApiManager
   */
  protected $apiManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var self $instance */
    $instance = parent::create($container, $configuration, $plugin_definition, $plugin_definition);
    $instance->apiManager = $container->get('commerce_klarna_payments.api_manager');
    $instance->logger = $container->get('logger.channel.commerce_klarna_payments');

    return $instance;
  }

  /**
   * Sets the connector.
   *
   * @param \Drupal\commerce_klarna_payments\ApiManager $apiManager
   *   The connector.
   *
   * @return $this
   *   The self.
   */
  public function setApiManager(ApiManager $apiManager) : self {
    $this->apiManager = $apiManager;
    return $this;
  }

  /**
   * Sets the logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   *
   * @return $this
   *   The self.
   */
  public function setLogger(LoggerInterface $logger) : self {
    $this->logger = $logger;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'test',
      'username' => '',
      'password' => '',
      'region' => 'eu',
      'cancel_fraudulent_orders' => FALSE,
      'options' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * Whether to cancel fraudulent orders automatically.
   *
   * @return bool
   *   TRUE if we should cancel fraudulent orders.
   */
  public function cancelFraudulentOrders() : bool {
    return $this->configuration['cancel_fraudulent_orders'] === TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isLive() : bool {
    return $this->configuration['mode'] === 'live';
  }

  /**
   * {@inheritdoc}
   */
  public function getRegion() : string {
    return $this->configuration['region'];
  }

  /**
   * Gets the username.
   *
   * @return string
   *   The username.
   */
  public function getUsername() : string {
    return $this->configuration['username'];
  }

  /**
   * Gets the password.
   *
   * @return string
   *   The password.
   */
  public function getPassword() : string {
    return $this->configuration['password'];
  }

  /**
   * Gets the entity id (plugin id).
   *
   * @return string
   *   The entity id.
   */
  public function getEntityId() : string {
    return $this->parentEntity->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getReturnUri(OrderInterface $order, string $routeName, array $arguments = []) : string {
    $arguments = array_merge($arguments, [
      'step' => 'payment',
      'commerce_order' => $order->id(),
      'commerce_payment_gateway' => $this->getEntityId(),
    ]);

    return (new Url($routeName, $arguments, ['absolute' => TRUE]))
      ->toString();
  }

  /**
   * {@inheritdoc}
   */
  public function getHost() : string {
    $host = static::REGIONS[$this->getRegion()][$this->isLive() ? 'live' : 'test'] ?? '';

    if ($host === '') {
      throw new \InvalidArgumentException('Host not found.');
    }
    return $host;
  }

  /**
   * Gets the client configuration.
   *
   * @return \Klarna\Configuration
   *   The configuration.
   */
  public function getClientConfiguration() : Configuration {
    $configuration = (new Configuration())
      ->setUsername($this->getUsername())
      ->setPassword($this->getPassword())
      ->setUserAgent('Libary drupal-klarna-payments-v1');

    $host = $this->getHost();
    $configuration->setHost($host);

    return $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['username'],
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['password'],
    ];

    $form['region'] = [
      '#title' => $this->t('Region'),
      '#type' => 'select',
      '#default_value' => $this->configuration['region'],
      '#options' => [
        'eu' => $this->t('Europe'),
        'na' => $this->t('North America'),
        'oc' => $this->t('Oceania'),
      ],
    ];

    $form['cancel_fraudulent_orders'] = [
      '#title' => $this->t('Cancel fraudulent orders automatically (US & UK only)'),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['cancel_fraudulent_orders'],
      '#description' => $this->t('Automatically cancel the Drupal order if Klarna deems the order fraudulent. <a href="@link">Read more.</a>', ['@link' => 'https://developers.klarna.com/documentation/order-management/pending-orders/#overriding-the-fraud-decision']),
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Additional options'),
      '#open' => FALSE,
    ];

    foreach ($this->getDefaultOptions() as $key => $title) {
      $form['options'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => $this->configuration['options'][$key] ?? NULL,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $this->configuration = $form_state->getValue($form['#parents']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    try {
      $orderResponse = $this->apiManager->getOrder($order);

      $allowed = ['AUTHORIZED', 'PART_CAPTURED', 'CAPTURED'];

      if (!in_array($orderResponse->getStatus(), $allowed)) {
        throw new PaymentGatewayException(
          $this->t('Order is in invalid state [@state], one of @expected expected.', [
            '@state' => $orderResponse->getStatus(),
            '@expected' => implode(',', $allowed),
          ])
        );
      }
      $this->createPayment($order);
      $this->apiManager->acknowledgeOrder($order, $orderResponse);
    }
    catch (\Exception $e) {
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Notify callback.
   *
   * This is only called if the fraud status was set to 'PENDING' during
   * the authorization process.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function onNotify(Request $request) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    if ((!$order_id = $request->query->get('commerce_order')) || !$order = Order::load($order_id)) {
      throw new PaymentGatewayException('Order not found.');
    }
    $content = json_decode($request->getContent());

    // Make sure our local order id matches with the one in payload.
    if ((!isset($content->order_id, $content->event_type)) || $content->order_id !== $order->getData('klarna_order_id')) {
      throw new AccessDeniedException('Order id mismatch.');
    }
    $this->apiManager->handleNotificationEvent($order, $content->event_type);

    $statuses = [
      'FRAUD_RISK_REJECTED',
      'FRAUD_RISK_STOPPED',
    ];
    // Cancel the order if configured to do so.
    if ($this->cancelFraudulentOrders() && in_array($content->event_type, $statuses)) {
      $order->getState()->applyTransitionById('cancel');

      $this->apiManager->voidPayment($order);
    }

    return new Response('', 200);
  }

  /**
   * Creates new payment in 'authorize' state.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_price\Price|null $amount
   *   The payment maount.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   The payment.
   */
  public function createPayment(OrderInterface $order, Price $amount = NULL) : PaymentInterface {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      // If not specified, use the entire amount.
      'amount' => $amount ?: $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'test' => !$this->isLive(),
    ]);
    $payment->getState()->applyTransitionById('authorize');

    $payment->save();

    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    try {
      $capture = $this->apiManager->createCapture($payment->getOrder(), $amount);
    }
    catch (\Exception $e) {
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }

    $payment->getState()->applyTransitionById('capture');

    $payment->setAmount($amount)
      ->setRemoteId($capture->getCaptureId())
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);
    $order = $payment->getOrder();

    try {
      $this->apiManager->voidPayment($order);
    }
    catch (\Exception $e) {
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }

    $payment->setState('authorization_voided')
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    // Validate the requested amount.
    $this->assertRefundAmount($payment, $amount);
    $order = $payment->getOrder();

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);

    try {
      // Idempotency key has to be unique for every refund event.
      // Use payment's UUID as a base and append refunded amount
      // to make sure it's always unique.
      $klarna_idempotency_key = $payment->uuid();
      $klarna_idempotency_key .= '-' . $new_refunded_amount->getNumber();

      $this->apiManager->refundPayment($order, $amount, $klarna_idempotency_key);
    }
    catch (\Exception $e) {
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }

    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

}
