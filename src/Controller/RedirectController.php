<?php

namespace Drupal\commerce_klarna_payments\Controller;

use Drupal\commerce_klarna_payments\Klarna\Exception\FraudException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Klarna\Rest\Transport\Exception\ConnectorException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handle Klarna payment redirects.
 */
class RedirectController extends ControllerBase {

  /**
   * Validates the authorization and handles the redirects.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route matcher.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect.
   */
  public function handleRedirect(RouteMatchInterface $routeMatch, Request $request) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $routeMatch->getParameter('commerce_order');
    /** @var \Drupal\commerce_klarna_payments\Plugin\Commerce\PaymentGateway\Klarna $plugin */
    $plugin = $routeMatch->getParameter('commerce_payment_gateway')
      ->getPlugin();

    $query = $request->request->all();
    $values = NestedArray::getValue($query, ['payment_process', 'offsite_payment']);

    if (empty($values['klarna_authorization_token'])) {
      $plugin->getLogger()->error(
        $this->t('Authorization token not set for #@id', [
          '@id' => $order->id(),
        ])
      );
      $this->messenger()->addError(
        $this->t('Authorization token not set. This should only happen when Klarna order is incomplete.')
      );

      // Redirect back to review step.
      return $this->redirectOnFailure($order);
    }

    try {
      $response = $plugin->getKlarnaConnector()
        ->authorizeOrder($order, $plugin, $values['klarna_authorization_token']);

      $redirect = new TrustedRedirectResponse($response->getRedirectUrl());

      // Send redirect immediately to prevent early rendering.
      return $redirect->send();
    }
    catch (\InvalidArgumentException | ConnectorException $e) {

      $plugin->getLogger()->critical(
        $this->t('Authorization validation failed for #@id: @message', [
          '@id' => $order->id(),
          '@message' => $e->getMessage(),
        ])
      );

      $this->messenger()->addError(
        $this->t('Authorization validation failed. Please contact store administration if the problemn persists.')
      );

      // Redirect back to review step.
      return $this->redirectOnFailure($order);
    }
    catch (FraudException $e) {

      $plugin->getLogger()->critical(
        $this->t('Fraudulent order validation failed for order #@id: @message', [
          '@id' => $order->id(),
          '@message' => $e->getMessage(),
        ])
      );

      $this->messenger()->addError(
        $this->t('Fraudulent order detected. Please contact store administration if the problemn persists.')
      );

      // Redirect back to review step.
      return $this->redirectOnFailure($order);
    }
  }

  /**
   * Redirects on failure.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect.
   */
  private function redirectOnFailure(OrderInterface $order) : RedirectResponse {
    return $this->redirect('commerce_checkout.form', [
      'commerce_order' => $order->id(),
      'step' => 'review',
    ]);
  }

}
