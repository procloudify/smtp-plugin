(function($) {
  'use strict';

  $(document).ready(function() {

    $('.procloudify-toggle-pw').on('click', function(e) {
      e.preventDefault();
      const $input = $('#password');
      const $icon = $(this).find('.dashicons');

      if ($input.attr('type') === 'password') {
        $input.attr('type', 'text');
        $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
      } else {
        $input.attr('type', 'password');
        $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
      }
    });

    $('#procloudify-test-form').on('submit', function(e) {
      e.preventDefault();

      const $btn = $('#btn-send-test');
      const $label = $btn.find('.btn-label');
      const originalText = $label.text();
      const toEmail = $('#test_to_email').val();
      const $alertBox = $('#procloudify-ajax-alert');

      if (!toEmail) return;

      $btn.prop('disabled', true);
      $label.text(procloudifySmtp.sending);
      $alertBox.hide().empty();

      $.ajax({
        url: procloudifySmtp.ajaxUrl,
        type: 'POST',
        data: {
          action: 'procloudify_ajax_test_email',
          nonce: procloudifySmtp.nonce,
          test_to_email: toEmail
        },
        success: function(res) {
          $btn.prop('disabled', false);
          $label.text(originalText);

          if (res.success) {
            $alertBox.html('<div class="notice notice-success inline is-dismissible"><p><strong>' + res.data.message + '</strong></p></div>').fadeIn(200);
          } else {
            $alertBox.html('<div class="notice notice-error inline is-dismissible"><p><strong>' + (res.data.message || 'Test delivery failed.') + '</strong></p></div>').fadeIn(200);
          }
        },
        error: function() {
          $btn.prop('disabled', false);
          $label.text(originalText);
          $alertBox.html('<div class="notice notice-error inline is-dismissible"><p><strong>Server connection error. Please try again.</strong></p></div>').fadeIn(200);
        }
      });
    });

  });

})(jQuery);
