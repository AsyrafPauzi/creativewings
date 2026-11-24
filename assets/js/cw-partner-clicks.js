(function () {
  if (!window.cwPartnerClicks || !cwPartnerClicks.ajaxUrl) return;

  document.addEventListener(
    'click',
    function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a.cwd-partner-logo[data-cw-partner-click]') : null;
      if (!a) return;
      var campaignId = a.getAttribute('data-cw-campaign-id') || '';
      var partnerKey = a.getAttribute('data-cw-partner-key') || '';
      if (!campaignId || !partnerKey) return;

      var body = new URLSearchParams();
      body.set('action', cwPartnerClicks.action);
      body.set('campaign_id', campaignId);
      body.set('partner_key', partnerKey);

      var payload = body.toString();
      if (navigator.sendBeacon) {
        try {
          navigator.sendBeacon(cwPartnerClicks.ajaxUrl, new Blob([payload], { type: 'application/x-www-form-urlencoded' }));
          return;
        } catch (err) {}
      }
      fetch(cwPartnerClicks.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload,
        keepalive: true,
        credentials: 'same-origin',
      }).catch(function () {});
    },
    true
  );
})();
