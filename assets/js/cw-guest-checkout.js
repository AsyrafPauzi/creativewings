(function ($) {
    'use strict';

    var cfg = window.cwGuestCheckout || null;
    if (!cfg) {
        return;
    }

    function parseDob(str) {
        if (!str || typeof str !== 'string') {
            return null;
        }
        var m = str.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) {
            return null;
        }
        var d = parseInt(m[1], 10);
        var mo = parseInt(m[2], 10) - 1;
        var y = parseInt(m[3], 10);
        var dt = new Date(y, mo, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo || dt.getDate() !== d) {
            return null;
        }
        return dt;
    }

    function formatDobDigits(digits) {
        digits = String(digits || '').replace(/\D/g, '').slice(0, 8);
        if (digits.length <= 2) {
            return digits;
        }
        if (digits.length <= 4) {
            return digits.slice(0, 2) + '/' + digits.slice(2);
        }
        return digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
    }

    function caretAfterDigitCount(count) {
        if (count <= 2) {
            return count;
        }
        if (count <= 4) {
            return count + 1;
        }
        return count + 2;
    }

    function autoFormatDobField() {
        var $field = $('#cw_guest_dob');
        if (!$field.length || $field.prop('readonly')) {
            return;
        }

        var el = $field[0];
        var raw = el.value || '';
        var digitPos = raw.slice(0, el.selectionStart).replace(/\D/g, '').length;
        var digits = raw.replace(/\D/g, '').slice(0, 8);
        var formatted = formatDobDigits(digits);

        if (formatted === raw) {
            return;
        }

        el.value = formatted;
        var newCaret = caretAfterDigitCount(Math.min(digitPos, digits.length));
        if (typeof el.setSelectionRange === 'function') {
            el.setSelectionRange(newCaret, newCaret);
        }
    }

    function ageFromDate(born) {
        var today = new Date();
        var age = today.getFullYear() - born.getFullYear();
        var m = today.getMonth() - born.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < born.getDate())) {
            age--;
        }
        return age;
    }

    function matchBracket(age) {
        var brackets = cfg.brackets || [];
        for (var i = 0; i < brackets.length; i++) {
            var b = brackets[i];
            var min = parseInt(b.min_age, 10);
            var max = parseInt(b.max_age, 10);
            if (isNaN(min)) {
                min = 0;
            }
            if (isNaN(max)) {
                max = 99;
            }
            if (age >= min && age <= max) {
                return b;
            }
        }
        return null;
    }

    function setPlaceOrderEnabled(enabled) {
        var $btn = $('#place_order');
        if (!$btn.length) {
            return;
        }
        $btn.prop('disabled', !enabled);
        $btn.toggleClass('cw-place-order-disabled', !enabled);
        if (cfg.orderButtonText) {
            $btn.text(cfg.orderButtonText);
            $btn.val(cfg.orderButtonText);
        }
    }

    function updateEligibility() {
        var $field = $('#cw_guest_dob');
        var $status = $('#cw-guest-age-status');
        if (!$field.length || !$status.length) {
            return;
        }

        var dob = $field.val();
        var born = parseDob(dob);

        $status.removeClass('is-ok is-error is-pending');

        if (!born) {
            $status.addClass('is-pending').text(cfg.i18n.enterDob || '');
            setPlaceOrderEnabled(false);
            return;
        }

        var age = ageFromDate(born);

        if (!cfg.ageBracketsEnabled) {
            $status
                .addClass('is-ok')
                .text((cfg.i18n.eligibleJoin || '').replace('%d', String(age)));
            setPlaceOrderEnabled(true);
            return;
        }

        if (!cfg.brackets || !cfg.brackets.length) {
            $status
                .addClass('is-ok')
                .text((cfg.i18n.eligibleJoin || '').replace('%d', String(age)));
            setPlaceOrderEnabled(true);
            return;
        }

        var match = matchBracket(age);
        if (match) {
            $status
                .addClass('is-ok')
                .text((cfg.i18n.eligibleCategory || '').replace('%s', match.label || ''));
            setPlaceOrderEnabled(true);
            return;
        }

        $status.addClass('is-error').text(cfg.i18n.notEligible || '');
        setPlaceOrderEnabled(false);
    }

    function onDobInput() {
        autoFormatDobField();
        updateEligibility();
    }

    function boot() {
        updateEligibility();
    }

    $(boot);
    $(document.body).on('updated_checkout', boot);
    $(document).on('input', '#cw_guest_dob', onDobInput);
    $(document).on('change', '#cw_guest_dob', updateEligibility);

    $(document.body).on('checkout_place_order', function () {
        var $btn = $('#place_order');
        if ($btn.length && $btn.prop('disabled')) {
            updateEligibility();
            return false;
        }
        return true;
    });
})(jQuery);
