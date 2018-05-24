/**
 * @file
 * Klarna payments widget.
 */

(function (window, Drupal, drupalSettings, Klarna) {

  'use strict';

  /**
   * Provides the Klarna payments widget.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior for the klarna payments.
   */
  Drupal.behaviors.klarnaPayments = {
    loading: false,

    attach: function () {
      if (this.loading) {
        return;
      }
      this.initialize(drupalSettings.klarnaPayments.client_token);
      this.load('pay_later', 'klarna-payments-container');

      // this.authorize('pay_later');
    },

    initialize: function(token) {
      this.loading = true;

      Klarna.Payments.init({
        client_token: token
      });
    },

    load: function(payment_method, container, data, callback) {
      try {
        Klarna.Payments.load({
            container: '#' + container,
            payment_method_category: payment_method
          },
          data,
          function (response) {
            console.log(response);

            if (callback) {
              callback(response);
            }
          });
      }
      catch (e) {
        console.log(e);
      }
    },

    sleep: function(ms) {
      return new Promise(resolve => setTimeout(resolve, ms));
    },

    authorize: async function(payment_method, data, callback) {
      await this.sleep(2000);

      Klarna.Payments.authorize({
        payment_method_category: payment_method,
      },
        data,
        function (response) {
          console.log(response);

          if (callback) {
            callback(response);
          }
        });
      this.loading = false;
    }
  };

})(window, Drupal, drupalSettings, Klarna);
