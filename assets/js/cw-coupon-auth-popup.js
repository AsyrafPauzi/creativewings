(function ($) {
    'use strict';

    var cfg = window.cwCouponAuth;
    if (!cfg || cfg.isLoggedIn) {
        return;
    }

    var pendingCoupon = '';
    var $modal = $('#cw-coupon-auth-modal');
    if (!$modal.length) {
        return;
    }

    function couponField() {
        return $('#coupon_code, input[name="coupon_code"]').first();
    }

    function readCoupon() {
        return String(couponField().val() || '').trim();
    }

    function showMsg(text, isError) {
        var $m = $('#cw-coupon-auth-msg');
        if (!text) {
            $m.attr('hidden', true).text('');
            return;
        }
        $m.prop('hidden', false).text(text).toggleClass('is-error', !!isError);
    }

    function setMode(mode) {
        var isLogin = mode === 'login';
        $('#cw-coupon-auth-register').prop('hidden', isLogin);
        $('#cw-coupon-auth-login').prop('hidden', !isLogin);
        $('#cw-coupon-auth-title').text(isLogin ? cfg.i18n.titleLogin : cfg.i18n.titleRegister);
        $('#cw-coupon-auth-register .cw-coupon-auth__submit').text(cfg.i18n.submitRegister);
        $('#cw-coupon-auth-login .cw-coupon-auth__submit').text(cfg.i18n.submitLogin);
        $('#cw-coupon-auth-register .cw-coupon-auth__switch').text(cfg.i18n.switchLogin);
        $('#cw-coupon-auth-login .cw-coupon-auth__switch').text(cfg.i18n.switchRegister);
    }

    function openModal(coupon, mode) {
        pendingCoupon = coupon;
        setMode(mode || 'register');
        showMsg('');
        $modal.prop('hidden', false);
        $('body').addClass('cw-coupon-auth-open');
        var $first = $modal.find('form:not([hidden]) input:visible').first();
        if ($first.length) {
            $first.trigger('focus');
        }
    }

    function closeModal() {
        $modal.prop('hidden', true);
        $('body').removeClass('cw-coupon-auth-open');
    }

    function applyCouponViaWc(code) {
        var $field = couponField();
        if ($field.length) {
            $field.val(code);
        }

        var $btn = $('button[name="apply_coupon"], input[name="apply_coupon"]').first();
        if ($btn.length) {
            $btn.data('cw-auth-bypass', 1);
            $btn.trigger('click');
            return;
        }

        $(document.body).trigger('apply_coupon', [code]);
        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.wc_ajax_url) {
            $.post(wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'), {
                security: wc_checkout_params.apply_coupon_nonce,
                coupon_code: code
            }).always(function () {
                $(document.body).trigger('update_checkout');
            });
        }
    }

    function checkRequiresAuth(code, onYes, onNo) {
        $.post(cfg.ajaxUrl, {
            action: 'cw_coupon_requires_auth',
            nonce: cfg.nonce,
            code: code
        }).done(function (res) {
            if (res && res.success && res.data && res.data.requires_auth) {
                onYes();
            } else {
                onNo();
            }
        }).fail(function () {
            onNo();
        });
    }

    function interceptApply($btn, e) {
        if ($btn.data('cw-auth-bypass')) {
            $btn.removeData('cw-auth-bypass');
            return false;
        }
        var code = readCoupon();
        if (!code) {
            return false;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        checkRequiresAuth(code, function () {
            openModal(code, 'register');
        }, function () {
            $btn.data('cw-auth-bypass', 1);
            $btn.trigger('click');
        });
        return true;
    }

    $(document).on('click', 'button[name="apply_coupon"], input[name="apply_coupon"]', function (e) {
        interceptApply($(this), e);
    });

    $(document).on('submit', 'form.checkout_coupon, form.woocommerce-form-coupon', function (e) {
        var $btn = $(this).find('button[name="apply_coupon"], input[name="apply_coupon"]').first();
        if (!$btn.length) {
            $btn = $(this).find('button[type="submit"], input[type="submit"]').first();
        }
        if ($btn.data('cw-auth-bypass')) {
            $btn.removeData('cw-auth-bypass');
            return;
        }
        var code = readCoupon();
        if (!code) {
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        checkRequiresAuth(code, function () {
            openModal(code, 'register');
        }, function () {
            $btn.data('cw-auth-bypass', 1);
            $(e.target).trigger('submit');
        });
    });

    $(document).on('keydown', '#coupon_code, input[name="coupon_code"]', function (e) {
        if (e.key !== 'Enter') {
            return;
        }
        var code = readCoupon();
        if (!code) {
            return;
        }
        e.preventDefault();
        $('button[name="apply_coupon"], input[name="apply_coupon"]').first().trigger('click');
    });

    $modal.on('click', '[data-cw-close]', closeModal);
    $modal.on('click', '[data-cw-mode]', function () {
        setMode($(this).data('cw-mode'));
        showMsg('');
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !$modal.prop('hidden')) {
            closeModal();
        }
    });

    function handleAuthFail(xhr, $registerForm) {
        var err = (xhr.responseJSON && xhr.responseJSON.data) || {};
        if (err.code === 'email_exists' && $registerForm) {
            $('#cw-coupon-auth-login [name=email]').val($registerForm.find('[name=email]').val());
            setMode('login');
            showMsg(cfg.i18n.emailExists, true);
            return;
        }
        showMsg(err.message || cfg.i18n.genericError, true);
    }

    $('#cw-coupon-auth-register').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this);
        var $submit = $f.find('.cw-coupon-auth__submit').prop('disabled', true);
        $.post(cfg.ajaxUrl, {
            action: 'cw_checkout_register',
            nonce: cfg.nonce,
            email: $f.find('[name=email]').val(),
            password: $f.find('[name=password]').val(),
            name: $f.find('[name=name]').val(),
            dob: $f.find('[name=dob]').val(),
            pdpa: $f.find('[name=pdpa]').is(':checked') ? 1 : 0,
            cw_hp_check: $f.find('[name=cw_hp_check]').val(),
            coupon: pendingCoupon
        }).done(function (res) {
            if (res && res.success) {
                closeModal();
                applyCouponViaWc(res.data.coupon || pendingCoupon);
                return;
            }
            var err = (res && res.data) || {};
            if (err.code === 'email_exists') {
                $('#cw-coupon-auth-login [name=email]').val($f.find('[name=email]').val());
                setMode('login');
                showMsg(cfg.i18n.emailExists, true);
                return;
            }
            showMsg(err.message || cfg.i18n.genericError, true);
        }).fail(function (xhr) {
            handleAuthFail(xhr, $f);
        }).always(function () {
            $submit.prop('disabled', false);
        });
    });

    $('#cw-coupon-auth-login').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this);
        var $submit = $f.find('.cw-coupon-auth__submit').prop('disabled', true);
        $.post(cfg.ajaxUrl, {
            action: 'cw_checkout_login',
            nonce: cfg.nonce,
            email: $f.find('[name=email]').val(),
            password: $f.find('[name=password]').val(),
            coupon: pendingCoupon
        }).done(function (res) {
            if (res && res.success) {
                closeModal();
                applyCouponViaWc(res.data.coupon || pendingCoupon);
                return;
            }
            var err = (res && res.data) || {};
            showMsg(err.message || cfg.i18n.genericError, true);
        }).fail(function (xhr) {
            handleAuthFail(xhr, null);
        }).always(function () {
            $submit.prop('disabled', false);
        });
    });
})(jQuery);
