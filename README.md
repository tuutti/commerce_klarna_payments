# Commerce Klarna Payments

[![Build Status](https://gitlab.com/tuutti/commerce_klarna_payments/badges/8.x-1.x/pipeline.svg)](https://gitlab.com/tuutti/commerce_klarna_payments)

## Description

This module integrates [Klarna Payments](https://www.klarna.com/) payment method with Drupal Commerce.

## Installation

`$ composer require drupal/commerce_klarna_payments`

## Usage

#### How to add/alter values before sending them to Klarna

See `\Drupal\commerce_klarna_payments\Event\Events` for available events and `\Drupal\commerce_klarna_payments\Event\RequestEvent` for available methods.


#### How to see more than one payment method

Payment methods are fetched via Klarna's javascript SDK and it should show all available payment methods to your account.

Test accounts seems to have a limited access to payment methods and as a result will only show "Pay later" payment method by default.
