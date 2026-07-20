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

    function clearChipStates() {
        $('.cw-guest-age-chip').removeClass('is-active is-muted');
    }

    function markMatchedChip(match) {
        var $chips = $('.cw-guest-age-chip');
        if (!$chips.length) {
            return;
        }
        $chips.addClass('is-muted').removeClass('is-active');
        if (!match) {
            return;
        }
        var key = match.key || '';
        var $active = key
            ? $chips.filter('[data-key="' + key.replace(/"/g, '\\"') + '"]')
            : $();
        if (!$active.length && typeof match.min_age !== 'undefined') {
            $active = $chips.filter(function () {
                return String($(this).data('min')) === String(match.min_age)
                    && String($(this).data('max')) === String(match.max_age);
            });
        }
        if ($active.length) {
            $active.removeClass('is-muted').addClass('is-active');
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
        clearChipStates();

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
            markMatchedChip(match);
            $status
                .addClass('is-ok')
                .text((cfg.i18n.eligibleCategory || '').replace('%s', match.label || ''));
            setPlaceOrderEnabled(true);
            return;
        }

        $('.cw-guest-age-chip').addClass('is-muted');
        $status.addClass('is-error').text(cfg.i18n.notEligible || '');
        setPlaceOrderEnabled(false);
    }

    function boot() {
        updateEligibility();
    }

    $(boot);
    $(document.body).on('updated_checkout', boot);
    $(document).on('change input', '#cw_guest_dob', updateEligibility);

    $(document.body).on('checkout_place_order', function () {
        var $btn = $('#place_order');
        if ($btn.length && $btn.prop('disabled')) {
            updateEligibility();
            return false;
        }
        return true;
    });
})(jQuery);
