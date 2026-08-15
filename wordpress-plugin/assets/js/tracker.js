(function () {
  'use strict';

  if (!window.NeoCRMConfig || !window.fetch) return;

  var config = window.NeoCRMConfig;
  var visitorKey = 'neocrm_visitor';
  var consentKey = 'neocrm_consent';
  var sessionKey = 'neocrm_session';
  var scrollSent = {};

  function uuid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function setCookie(name, value, days) {
    var expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function consent() {
    if (config.consentMode === 'disabled') return 'analytics';
    if (config.consentMode === 'external') return window.NeoCRMExternalConsent === true ? 'analytics' : 'essential';
    return getCookie(consentKey);
  }

  function params() {
    var search = new URLSearchParams(location.search);
    return {
      utm_source: search.get('utm_source') || '',
      utm_medium: search.get('utm_medium') || '',
      utm_campaign: search.get('utm_campaign') || ''
    };
  }

  function send(eventType, extra) {
    if (consent() !== 'analytics') return Promise.resolve(null);
		if (document.documentElement.hasAttribute('data-neocrm-no-track') || (document.body && document.body.hasAttribute('data-neocrm-no-track'))) return Promise.resolve(null);
    var campaign = params();
    var body = Object.assign({
      event_type: eventType,
      visitor_uuid: getCookie(visitorKey),
      session_uuid: sessionStorage.getItem(sessionKey) || '',
      consent: 'analytics',
      page_url: location.href,
			page_title: document.body && document.body.hasAttribute('data-neocrm-sensitive') ? '' : document.title,
      referrer: document.referrer,
      locale: document.documentElement.lang || navigator.language || '',
      utm_source: campaign.utm_source,
      utm_medium: campaign.utm_medium,
      utm_campaign: campaign.utm_campaign
    }, extra || {});

    return fetch(config.eventUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      keepalive: eventType === 'heartbeat'
    }).then(function (response) {
      if (!response.ok) return null;
      return response.json();
    }).then(function (data) {
      if (data && data.visitor_uuid) setCookie(visitorKey, data.visitor_uuid, config.cookieDays || 180);
      if (data && data.session_uuid) sessionStorage.setItem(sessionKey, data.session_uuid);
      syncVisitorFields();
      return data;
    }).catch(function () { return null; });
  }

  function showConsent() {
    if (config.consentMode !== 'required' || getCookie(consentKey)) return;
    var banner = document.createElement('div');
    banner.className = 'neocrm-consent-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', 'Analytics preferences');
    banner.innerHTML = '<p></p><div><button type="button" data-choice="essential"></button><button type="button" data-choice="analytics"></button></div>';
    banner.querySelector('p').textContent = config.i18n.banner;
    banner.querySelector('[data-choice="essential"]').textContent = config.i18n.essential;
    banner.querySelector('[data-choice="analytics"]').textContent = config.i18n.accept;
    banner.addEventListener('click', function (event) {
      var choice = event.target.getAttribute('data-choice');
      if (!choice) return;
      setCookie(consentKey, choice, 180);
      banner.remove();
      if (choice === 'analytics') start();
    });
    document.body.appendChild(banner);
  }

  function start() {
    if (consent() !== 'analytics') return;
    if (!sessionStorage.getItem(sessionKey)) sessionStorage.setItem(sessionKey, uuid());
    send('page_view');

    document.addEventListener('click', function (event) {
      var target = event.target.closest('a,button,[data-neocrm-track]');
			if (!target || target.closest('[data-neocrm-no-track]')) return;
			var label = target.getAttribute('data-neocrm-track') || target.getAttribute('aria-label') || target.tagName.toLowerCase();
      var href = target.href || '';
      send(/\.(pdf|docx?|xlsx?|zip)(\?|$)/i.test(href) ? 'download' : 'click', {
        element_name: label.trim().slice(0, 190),
        event_data: { href: href.slice(0, 255) }
      });
    }, { passive: true });

    window.addEventListener('scroll', function () {
      var height = Math.max(document.documentElement.scrollHeight - window.innerHeight, 1);
      var depth = Math.round((window.scrollY / height) * 100);
      [25, 50, 75, 90].forEach(function (mark) {
        if (depth >= mark && !scrollSent[mark]) {
          scrollSent[mark] = true;
          send('scroll', { event_data: { depth: mark } });
        }
      });
    }, { passive: true });

    setTimeout(function () { send('heartbeat', { event_data: { seconds: 30 } }); }, 30000);
  }

  function syncVisitorFields() {
    document.querySelectorAll('[name="visitor_uuid"]').forEach(function (field) {
      field.value = getCookie(visitorKey);
    });
  }

  function bindForms() {
    document.querySelectorAll('[data-neocrm-lead-form]').forEach(function (form) {
      var started = false;
      form.addEventListener('focusin', function () {
        if (!started) {
          started = true;
          send('form_started');
        }
      });
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        var status = form.querySelector('.neocrm-form-status');
        var values = Object.fromEntries(new FormData(form).entries());
        values.nonce = config.nonce;
        values.page_url = location.href;
        values.visitor_uuid = getCookie(visitorKey);
        values.marketing_consent = form.querySelector('[name="marketing_consent"]').checked;
        button.disabled = true;
        status.textContent = '';
        fetch(config.leadUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(values)
        }).then(function (response) {
          if (!response.ok) throw new Error('Lead capture failed');
          form.reset();
          syncVisitorFields();
          status.textContent = config.i18n.success;
          status.className = 'neocrm-form-status is-success';
        }).catch(function () {
          status.textContent = config.i18n.error;
          status.className = 'neocrm-form-status is-error';
        }).finally(function () { button.disabled = false; });
      });
    });
  }

  window.addEventListener('neocrm:consent', function (event) {
    if (event.detail && event.detail.analytics === true) {
      window.NeoCRMExternalConsent = true;
      start();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    syncVisitorFields();
    bindForms();
    showConsent();
    start();
  });
})();
