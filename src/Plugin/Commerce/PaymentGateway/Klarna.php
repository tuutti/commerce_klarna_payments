<?php

declare(strict_types = 1);

namespace Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_klarna_payments\KlarnaConnector;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Klarna\Rest\Transport\ConnectorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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
 * )
 */
final class Klarna extends OffsitePaymentGatewayBase implements SupportsNotificationsInterface {

  /**
   * The key used for EU region.
   *
   * @var string
   */
  protected const REGION_EU = 'eu';

  /**
   * The key used for NA region.
   *
   * @var string
   */
  protected const REGION_NA = 'na';

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The klarna connector.
   *
   * @var \Drupal\commerce_klarna_payments\KlarnaConnector
   */
  protected $connector;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    EventDispatcherInterface $eventDispatcher
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $payment_type_manager,
      $payment_method_type_manager,
      $time
    );

    $this->eventDispatcher = $eventDispatcher;
    $this->connector = new KlarnaConnector($eventDispatcher, $this);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'test',
      'username' => '',
      'password' => '',
      'locale' => 'automatic',
      'api_url' => NULL,
    ] + parent::defaultConfiguration();
  }

  /**
   * Gets the Klarna connector.
   *
   * @return \Drupal\commerce_klarna_payments\KlarnaConnector
   *   The connector.
   */
  public function getKlarnaConnector() : KlarnaConnector {
    return $this->connector;
  }

  /**
   * Gets the live mode status.
   *
   * @return bool
   *   Boolean indicating whether we are operating in live mode.
   */
  public function isLive() : bool {
    return $this->configuration['mode'] === 'live';
  }

  /**
   * Gets the region.
   *
   * @return string
   *   The region.
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
   * Gets the api uri.
   *
   * @return string
   *   The api uri.
   */
  public function getApiUri() : string {
    if ($this->getRegion() === static::REGION_EU) {
      return $this->isLive() ? ConnectorInterface::EU_BASE_URL : ConnectorInterface::EU_TEST_BASE_URL;
    }
    return $this->isLive() ? ConnectorInterface::NA_BASE_URL : ConnectorInterface::NA_TEST_BASE_URL;
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

    $form['locale'] = [
      '#title' => $this->t('Locale'),
      '#type' => 'select',
      '#options' => [
        'automatic' => $this->t('Automatic'),
      ],
    ];

    $form['region'] = [
      '#title' => $this->t('Region'),
      '#type' => 'select',
      '#options' => [
        static::REGION_EU => $this->t('Europe'),
        static::REGION_NA => $this->t('North America'),
      ],
    ];

    return $form;
  }

  /**
   * Gets the locale.
   *
   * @return string
   *   The locale.
   */
  public function getLocale() : string {
    // @todo Implement this.
    if ($this->configuration['locale'] === 'automatic') {
      return 'FI';
    }
    return $this->configuration['locale'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['mode'] = $values['mode'];
      $this->configuration['username'] = $values['username'];
      $this->configuration['password'] = $values['password'];
      $this->configuration['region'] = $values['region'];
    }
  }

}
