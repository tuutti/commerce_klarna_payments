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
    settings: {},

    attach: function () {
      if (this.loading) {
        return;
      }
      this.initialize(drupalSettings.klarnaPayments);
      this.load(this.settings.payment_method_category.identifier, 'klarna-payments-container');
    },

    initialize: function(settings) {
      this.loading = true;
      this.settings = settings;

      Klarna.Payments.init({
        client_token: settings.client_token
      });
    },

    load: function(payment_method, container, data) {
      var self = this;

      try {
        Klarna.Payments.load({
            container: '#' + container,
            payment_method_category: payment_method
          },
          data,
          function (response) {
            if (!response.show_form) {
              throw 'Failed to initialize form.';
            }
            self.authorize(payment_method);
          });
      }
      catch (e) {
        console.log(e);
      }
    },

    authorize: function(payment_method, data) {
      Klarna.Payments.authorize({
        payment_method_category: payment_method
      },
        data,
        function (response) {
          console.log(response);

          if (response.approved && response.show_form) {
            var input = document.querySelector('[klarna-selector="authorization-token"]');

            if (typeof input === 'undefined') {
              throw 'Authorization token input not found';
            }
            input.setAttribute('value', response.authorization_token);
          }
        });
      this.loading = false;
    }
  };

})(window, Drupal, drupalSettings, Klarna);
