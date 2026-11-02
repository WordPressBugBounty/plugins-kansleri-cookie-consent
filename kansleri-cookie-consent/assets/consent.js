(function () {
  'use strict';

  var COOKIE_NAME = 'kncc_consent';
  var COOKIE_DAYS = 365;
  var config = window.knccConfig || {};

  function getConsent() {
    var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE_NAME + '=([^;]*)'));
    if (!match) return null;
    try {
      return JSON.parse(decodeURIComponent(match[1]));
    } catch (e) {
      return null;
    }
  }

  function setConsent(consent) {
    consent.timestamp = new Date().toISOString();
    var val = encodeURIComponent(JSON.stringify(consent));
    var expires = new Date(Date.now() + COOKIE_DAYS * 864e5).toUTCString();
    var cookie = COOKIE_NAME + '=' + val + ';expires=' + expires + ';path=/;SameSite=Lax';
    if (location.protocol === 'https:') {
      cookie += ';Secure';
    }
    document.cookie = cookie;
  }

  function dispatchConsentCommand(payload) {
    window.dataLayer = window.dataLayer || [];
    // gtag.js only reads Arguments objects from the dataLayer and ignores a pushed array,
    // so define the standard helper when nothing has yet (e.g. Site Kit's tag is still blocked).
    if (typeof window.gtag !== 'function') {
      window.gtag = function () { window.dataLayer.push(arguments); };
    }
    window.gtag('consent', 'update', payload);
  }

  function updateConsentMode(consent) {
    if (!config.consentMode) return;

    dispatchConsentCommand({
      analytics_storage:        consent.analytics ? 'granted' : 'denied',
      ad_storage:               consent.marketing ? 'granted' : 'denied',
      ad_user_data:             consent.marketing ? 'granted' : 'denied',
      ad_personalization:       consent.marketing ? 'granted' : 'denied',
      functionality_storage:    consent.preferences ? 'granted' : 'denied',
      personalization_storage:  consent.preferences ? 'granted' : 'denied',
      security_storage:         'granted',
    });
  }

  function pushDataLayer(consent) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      event: 'consent_update',
      consent_necessary: true,
      consent_analytics: !!consent.analytics,
      consent_marketing: !!consent.marketing,
      consent_preferences: !!consent.preferences,
    });
  }

  function isCategoryGranted(consent, key) {
    if (key === 'necessary') {
      return true;
    }
    if (consent.choice === 'accept') {
      return true;
    }
    if (consent.choice === 'reject') {
      return false;
    }
    var value = consent[key];
    return value === true || value === 1 || value === '1' || value === 'true';
  }

  function getWpConsentPrefix() {
    if (typeof window.consent_api !== 'undefined' && consent_api.cookie_prefix) {
      return consent_api.cookie_prefix;
    }
    return 'wp_consent';
  }

  function dispatchWpConsentChange(category, value) {
    try {
      var detail = {};
      detail[category] = value;
      document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change', { detail: detail }));
    } catch (e) {}
  }

  function setWpConsentCookie(category, value) {
    if (value !== 'allow' && value !== 'deny') {
      return;
    }

    if (typeof wp_set_consent === 'function') {
      wp_set_consent(category, value);
      return;
    }

    // Fallback: WP Consent API JS not ready (e.g. delayed by a caching plugin).
    // Write the cookie directly so the value is never left stale, then notify
    // integrators like Google Site Kit that consent changed.
    var prefix = getWpConsentPrefix();
    var days = (typeof window.consent_api !== 'undefined' && consent_api.cookie_expiration)
      ? consent_api.cookie_expiration
      : 30;
    var date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    var secure = location.protocol === 'https:' ? ';secure' : '';
    document.cookie = prefix + '_' + category + '=' + value + secure + ';expires=' + date.toUTCString() + ';path=/';
    dispatchWpConsentChange(category, value);
  }

  function clearStaleWpConsentCookie(category) {
    var prefix = getWpConsentPrefix();
    var name = prefix + '_' + category;
    getDeletionDomains().forEach(function (domain) {
      deleteCookie(name, domain || null);
    });
  }

  /**
   * Whether the WP Consent API is in use on this page. Its cookies (wp_consent_*) are written only then: PHP
   * says so in knccConfig.wpConsentMap (also when an optimizer delays the plugin's script) or the script is
   * already here. Without it the choice stays in kncc_consent and nothing else is stored.
   */
  function wpConsentApiInUse() {
    return !!config.wpConsentMap || typeof wp_set_consent === 'function' || typeof window.consent_api !== 'undefined';
  }

  /**
   * Up to 1.8.11 the banner wrote these cookies on every site. Take them off the browsers that still have them.
   * Only the host's own cookies go (the ones written here have no domain), so a cookie of another site on a
   * parent domain is left alone.
   */
  function removeUnusedWpConsentCookies() {
    ['functional', 'statistics', 'statistics-anonymous', 'marketing', 'preferences'].forEach(function (category) {
      var name = 'wp_consent_' + category;
      if (new RegExp('(?:^|; )' + name + '=').test(document.cookie)) {
        deleteCookie(name, null);
      }
    });
  }

  function syncWpConsentApi(consent) {
    if (!consent || !wpConsentApiInUse()) return;

    var analytics = isCategoryGranted(consent, 'analytics');

    // Remove any stale statistics cookies (host + parent domains) before writing,
    // so a lingering deny on a parent domain cannot shadow the fresh value.
    if (analytics) {
      clearStaleWpConsentCookie('statistics');
      clearStaleWpConsentCookie('statistics-anonymous');
    }

    setWpConsentCookie('functional', 'allow');
    setWpConsentCookie('statistics', analytics ? 'allow' : 'deny');
    setWpConsentCookie('statistics-anonymous', analytics ? 'allow' : 'deny');
    setWpConsentCookie('marketing', isCategoryGranted(consent, 'marketing') ? 'allow' : 'deny');
    setWpConsentCookie('preferences', isCategoryGranted(consent, 'preferences') ? 'allow' : 'deny');
  }

  function scheduleWpConsentSync(consent) {
    if (!consent) return;
    if (!wpConsentApiInUse()) {
      removeUnusedWpConsentCookies();
      return;
    }
    syncWpConsentApi(consent);
    if (typeof wp_set_consent !== 'function') {
      setTimeout(function () { syncWpConsentApi(consent); }, 50);
      setTimeout(function () { syncWpConsentApi(consent); }, 250);
    }
  }

  function renderPolicyConsentStatus() {
    var el = document.getElementById('kncc-policy-consent-status');
    if (!el) return;

    var labels = {};
    try {
      labels = JSON.parse(el.getAttribute('data-labels') || '{}');
    } catch (e) {
      labels = {};
    }

    var cats = (el.getAttribute('data-categories') || '').split(',').filter(function (cat) {
      return cat !== '';
    });
    if (!cats.length) return;

    var strings = config.policyStatus || {};
    var consent = getConsent();

    el.textContent = '';

    if (!consent) {
      var note = document.createElement('p');
      note.className = 'kncc-policy__consent-status-note';
      note.textContent = strings.noChoice || 'You have not made a choice yet.';
      el.appendChild(note);
      return;
    }

    var list = document.createElement('ul');
    list.className = 'kncc-policy__consent-status-list';

    cats.forEach(function (cat) {
      var item = document.createElement('li');
      var statusText;
      if (cat === 'necessary') {
        statusText = strings.alwaysActive || 'Always active';
      } else if (isCategoryGranted(consent, cat)) {
        statusText = strings.allowed || 'Allowed';
      } else {
        statusText = strings.denied || 'Denied';
      }
      item.textContent = (labels[cat] || cat) + ': ' + statusText;
      list.appendChild(item);
    });

    el.appendChild(list);
  }

  function deleteCookie(name, domain) {
    var expired = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
    document.cookie = expired;
    if (domain) {
      document.cookie = expired + ';domain=' + domain;
    }
  }

  function getDeletionDomains() {
    var hostname = location.hostname;
    var domains = [''];
    if (hostname.indexOf('www.') === 0) {
      domains.push('.' + hostname.substring(4));
    }
    domains.push('.' + hostname);
    var parts = hostname.split('.');
    if (parts.length > 2) {
      domains.push('.' + parts.slice(-2).join('.'));
    }
    return domains;
  }

  function matchesClearPattern(name, pattern) {
    if (!pattern) return false;
    if (pattern.charAt(pattern.length - 1) === '*') {
      return name.indexOf(pattern.slice(0, -1)) === 0;
    }
    return name === pattern;
  }

  function clearRejectedCategoryCookies(consent) {
    var patterns = config.cookiePatterns || {};
    var domains = getDeletionDomains();
    var optional = ['analytics', 'marketing', 'preferences'];

    document.cookie.split(';').forEach(function (entry) {
      var name = entry.split('=')[0].trim();
      if (!name || name === COOKIE_NAME) return;
      if (name.indexOf('wp_consent_') === 0) return;

      optional.forEach(function (cat) {
        if (consent[cat]) return;
        (patterns[cat] || []).forEach(function (pattern) {
          if (matchesClearPattern(name, pattern)) {
            domains.forEach(function (domain) {
              deleteCookie(name, domain || null);
            });
          }
        });
      });
    });

    // Some services keep their identifier in localStorage instead of a cookie (e.g. Trustmary's
    // "tmary"). Only exact names are removed here: a prefix could match the site's own data.
    try {
      optional.forEach(function (cat) {
        if (consent[cat]) return;
        (patterns[cat] || []).forEach(function (pattern) {
          if (pattern && pattern.charAt(pattern.length - 1) !== '*' && window.localStorage.getItem(pattern) !== null) {
            window.localStorage.removeItem(pattern);
          }
        });
      });
    } catch (e) {}
  }

  function recordStat(choice, consent) {
    if (!config.ajaxUrl) return;
    var body = new FormData();
    body.append('action', 'kncc_record_consent');
    body.append('choice', choice);
    body.append('nonce', config.statsNonce || '');
    body.append('categories', JSON.stringify({
      necessary: true,
      analytics: !!consent.analytics,
      marketing: !!consent.marketing,
      preferences: !!consent.preferences,
    }));
    // sendBeacon reports failure by returning false rather than throwing, so the
    // return value has to be checked or a dropped beacon never reaches the fallback.
    var queued = false;
    try {
      queued = !!(navigator.sendBeacon && navigator.sendBeacon(config.ajaxUrl, body));
    } catch (e) {
      queued = false;
    }

    if (!queued) {
      fetch(config.ajaxUrl, { method: 'POST', body: body, keepalive: true }).catch(function () {});
    }
  }

  /* ── Script / iframe activation after consent ── */

  /**
   * Whether the consent allows a data-kncc-category value. A Google Tag Manager container carries
   * "analytics marketing": any one of the listed categories is enough.
   */
  function isAnyGranted(consent, cats) {
    return String(cats || '').split(' ').some(function (cat) { return cat && !!consent[cat]; });
  }

  function activateBlockedScripts(consent) {
    // Keep the text/plain source in the DOM and mark it loaded. Removing it made
    // Reject → Accept impossible without a full page reload (nothing left to run).
    var blocked = document.querySelectorAll('script[type="text/plain"][data-kncc-category]');
    blocked.forEach(function (el) {
      var cat = el.getAttribute('data-kncc-category');
      if (!isAnyGranted(consent, cat)) return;
      if (el.getAttribute('data-kncc-loaded') === '1') return;

      var newScript = document.createElement('script');
      // Copy every attribute except type and id: trackers such as Plausible (data-domain)
      // and Fathom (data-site) read their config from document.currentScript, i.e. this
      // clone. The id stays on the source tag so the page never has two of it.
      Array.prototype.forEach.call(el.attributes, function (attr) {
        if (attr.name !== 'type' && attr.name !== 'id') {
          newScript.setAttribute(attr.name, attr.value);
        }
      });
      newScript.type = el.getAttribute('data-kncc-type') === 'module' ? 'module' : 'text/javascript';
      newScript.setAttribute('data-kncc-injected', '1');

      if (!el.src) {
        newScript.textContent = el.textContent;
      }

      el.parentNode.insertBefore(newScript, el.nextSibling);
      el.setAttribute('data-kncc-loaded', '1');
    });

    // Scripts that other code (e.g. Google Tag Manager) added after load, held by the guard in the head.
    if (typeof window.knccReleaseHeld === 'function') {
      window.knccReleaseHeld(consent);
    }

    var iframes = document.querySelectorAll('iframe[data-kncc-src][data-kncc-category]');
    iframes.forEach(function (el) {
      var cat = el.getAttribute('data-kncc-category');
      if (!isAnyGranted(consent, cat)) return;

      el.src = el.getAttribute('data-kncc-src');
      el.removeAttribute('data-kncc-src');
      el.classList.remove('kncc-blocked-iframe');
      var placeholder = el.previousElementSibling;
      if (placeholder && placeholder.classList.contains('kncc-iframe-placeholder')) {
        placeholder.remove();
      }
    });

    ['analytics', 'marketing', 'preferences'].forEach(function (cat) {
      if (!consent[cat]) return;
      activateElementorVideos(cat);
      // Widget scripts (e.g. a reviews widget) now show their own content.
      document.querySelectorAll('.kncc-script-placeholder[data-kncc-category="' + cat + '"]').forEach(function (el) {
        el.remove();
      });
    });
  }

  /* ── Elementor Video widget ── */

  function youtubeIdFromUrl(url) {
    var m = String(url || '').match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:(?:watch)?\?(?:.*&)?vi?=|(?:embed|v|vi|shorts|live)\/))([A-Za-z0-9_-]{11})/);
    return m ? m[1] : '';
  }

  /**
   * The YouTube iframe Elementor's own player would have shown, built from the widget's
   * frontend settings (Elementor switchers are 'yes' or '').
   */
  function elementorYoutubeIframe(settings, cat, autoplay) {
    var id = youtubeIdFromUrl(settings.youtube_url);
    if (!id) return null;

    var params = [];
    var on = function (key) { return settings[key] === 'yes'; };
    var seconds = function (key) { var n = parseInt(settings[key], 10); return n > 0 ? n : 0; };
    if (autoplay) params.push('autoplay=1');
    if (on('mute')) params.push('mute=1');
    if (on('loop')) params.push('loop=1', 'playlist=' + id);
    if (settings.controls === '') params.push('controls=0');
    if (on('cc_load_policy')) params.push('cc_load_policy=1');
    if (on('play_on_mobile')) params.push('playsinline=1');
    if (seconds('start')) params.push('start=' + seconds('start'));
    if (seconds('end')) params.push('end=' + seconds('end'));

    var iframe = document.createElement('iframe');
    iframe.className = 'elementor-video-iframe';
    iframe.src = 'https://www.' + (on('yt_privacy') ? 'youtube-nocookie' : 'youtube') + '.com/embed/' + id + (params.length ? '?' + params.join('&') : '');
    iframe.title = 'YouTube video player';
    iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    if (on('lazy_load')) iframe.setAttribute('loading', 'lazy');
    // Lets resetBlockedScriptsForRejected() park it again like any other embed.
    iframe.setAttribute('data-kncc-category', cat);
    return iframe;
  }

  /**
   * Elementor Video widgets are neutralised on the server (data-widget_type renamed, lightbox
   * attributes renamed, iframes parked), so Elementor never starts their player. After consent,
   * build the player here, or hand the lightbox back to Elementor's document-level click handler.
   * data-widget_type stays renamed: Elementor attaching its handler late would create a second player.
   */
  function activateElementorVideos(cat) {
    document.querySelectorAll('[data-kncc-widget_type][data-kncc-category="' + cat + '"]').forEach(function (widget) {
      if (widget.getAttribute('data-kncc-activated') === '1') return;
      widget.setAttribute('data-kncc-activated', '1');

      widget.querySelectorAll('.kncc-iframe-placeholder').forEach(function (el) { el.remove(); });

      var lightbox = false;
      ['open-lightbox', 'lightbox'].forEach(function (name) {
        widget.querySelectorAll('[data-kncc-' + name + ']').forEach(function (el) {
          el.setAttribute('data-elementor-' + name, el.getAttribute('data-kncc-' + name));
          el.removeAttribute('data-kncc-' + name);
          lightbox = true;
        });
      });
      if (lightbox) return;

      var settings = {};
      try {
        settings = JSON.parse(widget.getAttribute('data-settings') || '{}') || {};
      } catch (e) {}

      var start = function (autoplay) {
        if (settings.video_type === 'youtube') {
          var holder = widget.querySelector('.elementor-video');
          var iframe = holder && elementorYoutubeIframe(settings, cat, autoplay);
          if (iframe) holder.parentNode.replaceChild(iframe, holder);
          return;
        }
        // Vimeo: Elementor printed the iframe; a lazy-loaded one keeps its URL in data-lazy-load.
        widget.querySelectorAll('iframe[data-kncc-category]').forEach(function (frame) {
          var url = frame.getAttribute('data-kncc-src') || frame.getAttribute('data-lazy-load');
          if (url && !frame.getAttribute('src')) frame.src = url;
          frame.removeAttribute('data-kncc-src');
          frame.classList.remove('kncc-blocked-iframe');
        });
      };

      // With an image overlay, Elementor plays the video when the overlay is clicked.
      var overlay = widget.querySelector('.elementor-custom-embed-image-overlay');
      if (!overlay) {
        start(settings.autoplay === 'yes');
        return;
      }
      var play = function () {
        overlay.removeEventListener('click', play);
        overlay.removeEventListener('keydown', onKey);
        overlay.remove();
        start(true);
      };
      var onKey = function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          play();
        }
      };
      overlay.addEventListener('click', play);
      overlay.addEventListener('keydown', onKey);
    });
  }

  /**
   * After Reject / custom deny: allow the same scripts to run again on the next Accept
   * without reloading the page.
   */
  function resetBlockedScriptsForRejected(consent) {
    // A script stays released while any of its categories is still allowed.
    document.querySelectorAll('script[type="text/plain"][data-kncc-category]').forEach(function (el) {
      if (!isAnyGranted(consent, el.getAttribute('data-kncc-category'))) el.removeAttribute('data-kncc-loaded');
    });

    document.querySelectorAll('script[data-kncc-injected="1"][data-kncc-category]').forEach(function (el) {
      if (!isAnyGranted(consent, el.getAttribute('data-kncc-category'))) el.remove();
    });

    var optional = ['analytics', 'marketing', 'preferences'];
    optional.forEach(function (cat) {
      if (consent[cat]) return;

      document.querySelectorAll('iframe[data-kncc-category="' + cat + '"]').forEach(function (el) {
        if (!el.getAttribute('data-kncc-src') && el.getAttribute('src')) {
          el.setAttribute('data-kncc-src', el.getAttribute('src'));
          el.removeAttribute('src');
          el.classList.add('kncc-blocked-iframe');
        }
      });

      // Take an Elementor lightbox trigger back, so it cannot open the video until the next Accept.
      document.querySelectorAll('[data-kncc-widget_type][data-kncc-category="' + cat + '"]').forEach(function (widget) {
        ['open-lightbox', 'lightbox'].forEach(function (name) {
          widget.querySelectorAll('[data-elementor-' + name + ']').forEach(function (el) {
            el.setAttribute('data-kncc-' + name, el.getAttribute('data-elementor-' + name));
            el.removeAttribute('data-elementor-' + name);
          });
        });
        widget.removeAttribute('data-kncc-activated');
      });
    });
  }

  function applyConsent(consent, choice) {
    setConsent(consent);
    scheduleWpConsentSync(consent);
    updateConsentMode(consent);
    pushDataLayer(consent);
    activateBlockedScripts(consent);
    if (choice) recordStat(choice, consent);
    showFloatingBtn();
    hideBanner();
    renderPolicyConsentStatus();
  }

  function acceptAll() {
    var consent = { necessary: true, analytics: true, marketing: true, preferences: true, choice: 'accept' };
    applyConsent(consent, 'accept');
  }

  function rejectAll() {
    var consent = { necessary: true, analytics: false, marketing: false, preferences: false, choice: 'reject' };
    clearRejectedCategoryCookies(consent);
    resetBlockedScriptsForRejected(consent);
    applyConsent(consent, 'reject');
  }

  function saveCustom() {
    var consent = { necessary: true, choice: 'custom' };
    var optional = ['analytics', 'marketing', 'preferences'];
    var checks = document.querySelectorAll('.kncc-category__check');
    var present = {};

    checks.forEach(function (el) {
      var cat = el.getAttribute('data-category');
      if (cat !== 'necessary') {
        consent[cat] = el.checked;
        present[cat] = true;
      }
    });

    optional.forEach(function (cat) {
      if (!present[cat]) {
        consent[cat] = false;
      }
    });

    clearRejectedCategoryCookies(consent);
    resetBlockedScriptsForRejected(consent);
    applyConsent(consent, 'custom');
  }

  function closeBanner() {
    // Reveal the floating button first: hideBanner() restores focus to whatever
    // opened the banner, which is often this button, and a hidden element cannot
    // receive focus.
    showFloatingBtn();
    hideBanner();
  }

  var banner = null;
  var details = null;
  var floatingBtn = null;
  var lastFocusedBeforeBanner = null;

  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  function isBannerVisible() {
    return !!banner && banner.style.display !== 'none';
  }

  function isModalBanner() {
    return !!banner && banner.getAttribute('data-kncc-modal') === '1';
  }

  function focusableInBanner() {
    if (!banner) return [];
    return Array.prototype.filter.call(banner.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
    });
  }

  /**
   * Keep Tab inside the modal banner. Bar and corner banners do not block the page,
   * so tabbing out of them is the expected behaviour.
   */
  function trapTab(e) {
    if (e.key !== 'Tab' || !isModalBanner() || !isBannerVisible()) return;

    var items = focusableInBanner();
    if (!items.length) return;

    var first = items[0];
    var last = items[items.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function showBanner(trigger) {
    if (banner) {
      lastFocusedBeforeBanner = trigger || document.activeElement;

      banner.style.display = '';
      banner.removeAttribute('aria-hidden');

      var existing = getConsent();
      if (existing) {
        var checks = banner.querySelectorAll('.kncc-category__check');
        checks.forEach(function (el) {
          var cat = el.getAttribute('data-category');
          if (cat !== 'necessary' && existing[cat] !== undefined) {
            el.checked = existing[cat];
          }
        });
      }

      // A modal banner traps focus, so it must receive it. A bar or corner banner
      // shown automatically on page load must not steal focus from the document;
      // it only takes focus when the visitor deliberately reopened it.
      if (trigger || isModalBanner()) {
        var items = focusableInBanner();
        if (items.length) {
          items[0].focus();
        }
      }
    }
  }

  function hideBanner() {
    if (banner) {
      banner.style.display = 'none';
      banner.setAttribute('aria-hidden', 'true');
    }
    if (details) {
      details.style.display = 'none';
    }

    // Return focus to whatever opened the banner so keyboard users are not dropped
    // back at the top of the document.
    if (lastFocusedBeforeBanner && document.contains(lastFocusedBeforeBanner)) {
      try { lastFocusedBeforeBanner.focus(); } catch (e) {}
    }
    lastFocusedBeforeBanner = null;
  }

  function showFloatingBtn() {
    if (floatingBtn) {
      floatingBtn.style.display = '';
    }
  }

  function hideFloatingBtn() {
    if (floatingBtn) {
      floatingBtn.style.display = 'none';
    }
  }

  function toggleDetails() {
    if (!details) return;
    var hidden = details.style.display === 'none';
    details.style.display = hidden ? '' : 'none';
  }

  /* ── Inline iframe accept buttons ── */

  function initIframeAcceptButtons() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-kncc-iframe-accept]');
      if (!btn) return;

      var cat = btn.getAttribute('data-kncc-iframe-accept');
      var consent = getConsent() || { necessary: true, analytics: false, marketing: false, preferences: false };
      consent[cat] = true;
      // Granting one category is a custom choice. Left at 'reject', isCategoryGranted() and
      // the WP Consent API sync (JS and PHP) would keep reporting this category as denied.
      consent.choice = 'custom';
      setConsent(consent);
      scheduleWpConsentSync(consent);
      updateConsentMode(consent);
      pushDataLayer(consent);

      // Run everything the category was holding back: scripts as well as iframes, Elementor
      // videos and widgets. Other granted categories were already activated on page load.
      activateBlockedScripts(consent);

      // Remove remaining placeholders for this category
      var placeholders = document.querySelectorAll('.kncc-iframe-placeholder[data-kncc-category="' + cat + '"]');
      placeholders.forEach(function (el) { el.remove(); });
      renderPolicyConsentStatus();
    });
  }

  /* ── Global API for reopening consent from policy page or external links ── */

  window.knccEditConsent = function (trigger) {
    hideFloatingBtn();
    showBanner(trigger || document.activeElement);
  };

  /**
   * Delegated handler for the [kncc_cookie_settings] shortcode link and the policy
   * page's "Edit cookie preferences" button, so neither needs an inline onclick.
   */
  function initOpenSettingsControls() {
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('.kncc-open-settings, .kncc-edit-consent-btn');
      if (!trigger) return;

      e.preventDefault();
      window.knccEditConsent(trigger);
    });

    // The shortcode link has role="button", and a button also opens with the Space key.
    document.addEventListener('keydown', function (e) {
      if (e.key !== ' ' && e.key !== 'Spacebar') return;
      var trigger = e.target && e.target.closest && e.target.closest('.kncc-open-settings[role="button"]');
      if (!trigger) return;

      e.preventDefault();
      window.knccEditConsent(trigger);
    });
  }

  function init() {
    renderPolicyConsentStatus();

    var consent = getConsent();
    if (consent) {
      scheduleWpConsentSync(consent);
      clearRejectedCategoryCookies(consent);
      updateConsentMode(consent);
      activateBlockedScripts(consent);
    }

    banner = document.getElementById('kncc-banner');
    floatingBtn = document.getElementById('kncc-floating-btn');

    initOpenSettingsControls();

    if (!banner) {
      if (consent && floatingBtn) {
        showFloatingBtn();
      }
      return;
    }

    details = banner.querySelector('.kncc-details');

    // The banner is printed at the end of the page, after everything it needs to know about the page.
    // It is fixed on screen, so moving it to the start of the document changes nothing visible, and
    // keyboard and screen reader users reach it first instead of after the whole page.
    if (banner.parentNode === document.body && document.body.firstChild !== banner) {
      document.body.insertBefore(banner, document.body.firstChild);
    }

    banner.addEventListener('click', function (e) {
      var target = e.target.closest('[data-kncc]');
      if (!target) return;

      var action = target.getAttribute('data-kncc');
      switch (action) {
        case 'accept':
          acceptAll();
          break;
        case 'reject':
          rejectAll();
          break;
        case 'toggle-details':
          toggleDetails();
          break;
        case 'close':
          closeBanner();
          break;
        case 'save':
          saveCustom();
          break;
      }
    });

    if (floatingBtn) {
      floatingBtn.addEventListener('click', function () {
        hideFloatingBtn();
        showBanner(floatingBtn);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (!isBannerVisible()) return;

      if (e.key === 'Escape') {
        closeBanner();
        return;
      }

      trapTab(e);
    });

    initIframeAcceptButtons();

    if (consent) {
      showFloatingBtn();
    } else {
      showBanner();
    }
  }

  var existingConsent = getConsent();
  if (existingConsent) {
    scheduleWpConsentSync(existingConsent);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
