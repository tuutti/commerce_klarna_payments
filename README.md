# Commerce Klarna Payments

![CI](https://github.com/tuutti/commerce_klarna_payments/workflows/CI/badge.svg)

This module integrates [Klarna Payments](https://www.klarna.com/) payment method with Drupal Commerce.

- For a full description of the module, visit the project page: https://www.drupal.org/project/commerce_klarna_payments

- To submit bug reports and feature suggestions, or to track changes: https://www.drupal.org/project/issues/commerce_klarna_payments

## Installation

`$ composer require drupal/commerce_klarna_payments`

## Configuration

1. Configure the Commerce Paytrail gateway from the Administration > Commerce > Configuration > Payment Gateways (`/admin/commerce/config/payment-gateways`), by editing an existing or adding a new payment gateway.

2. Select 'Klarna Payments' for the payment gateway plugin. Klarna Payments-specific fields will appear in the settings.
   - `Username`: provide your Klarna Payments API username.
   - `Secret`: provide your Klarna Payments API password.
   - `Cancel fraudulent orders automatically`: Automatically cancel the Drupal order if Klarna deems the order fraudulent. See https://docs.klarna.com/order-management/pending-orders/ for more information.

## Usage

### How to alter API requests

This module provides multiple events that allows API requests to be altered.

See `\Drupal\commerce_klarna_payments\Event\Events` for available events and `\Drupal\commerce_klarna_payments\Event\RequestEvent` for available methods.

See https://www.drupal.org/docs/creating-modules/subscribe-to-and-dispatch-events for more information about events.
