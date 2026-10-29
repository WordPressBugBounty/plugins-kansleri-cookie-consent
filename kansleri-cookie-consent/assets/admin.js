(function () {
  'use strict';

  var cfg = window.knccAdmin || {};
  var ajaxUrl = cfg.ajaxUrl;
  var nonce = cfg.nonce;
  var strings = cfg.strings || {};

  function escHtml(s) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(s || ''));
    // innerHTML leaves quotes alone; escape them too, since results also go into attributes.
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function showNotice(msg, type) {
    var el = document.getElementById('kncc-notice');
    if (!el) return;
    el.textContent = msg;
    el.className = 'kncc-notice kncc-notice--' + (type || 'success');
    el.style.display = '';
    setTimeout(function () { el.style.display = 'none'; }, 4000);
  }

  function serializeForm(form) {
    var data = new FormData(form);
    return new URLSearchParams(data);
  }

  /* ── Settings forms (General, Policy, Integration) ── */

  function initSettingsForms() {
    var forms = document.querySelectorAll('#kncc-general-form, #kncc-policy-form, #kncc-integration-form');
    forms.forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = serializeForm(form);
        body.append('action', 'kncc_save_settings');
        body.append('nonce', nonce);
        body.append('kncc_lang', strings.knccLang || '');

        fetch(ajaxUrl, { method: 'POST', body: body })
          .then(function (r) {
            var ct = r.headers.get('content-type') || '';
            if (!r.ok || ct.indexOf('application/json') === -1) {
              return r.text().then(function (txt) { throw new Error(txt.substring(0, 200)); });
            }
            return r.json();
          })
          .then(function (res) {
            if (res.success) {
              location.reload();
            } else {
              showNotice(res.data || strings.error, 'error');
            }
          })
          .catch(function (err) { showNotice(err.message || strings.error, 'error'); });
      });
    });
  }

  /* ── Cookies CRUD ── */

  var cookieIndex = 0;

  function initCookiesTab() {
    var tbody = document.getElementById('kncc-cookie-tbody');
    var form = document.getElementById('kncc-cookies-form');
    var addBtn = document.getElementById('kncc-add-cookie-btn');
    var scanBtn = document.getElementById('kncc-scan-btn');
    var copyPromptBtn = document.getElementById('kncc-copy-ai-prompt-btn');
    var aiHelper = document.getElementById('kncc-ai-helper');
    var importArea = document.getElementById('kncc-import-json-area');
    var importTextarea = document.getElementById('kncc-import-json-textarea');
    var importBtn = document.getElementById('kncc-import-json-btn');

    if (!tbody) return;

    var rows = tbody.querySelectorAll('tr[data-index]');
    rows.forEach(function (r) {
      var idx = parseInt(r.getAttribute('data-index'), 10);
      if (idx >= cookieIndex) cookieIndex = idx + 1;
    });

    if (addBtn) {
      addBtn.addEventListener('click', function () { addRow(tbody); });
    }

    tbody.addEventListener('click', function (e) {
      if (e.target.classList.contains('kncc-remove-cookie')) {
        var row = e.target.closest('tr');
        if (row) row.remove();
        if (!tbody.querySelector('tr[data-index]')) {
          tbody.innerHTML = '<tr class="kncc-no-cookies"><td colspan="6">' + escHtml(strings.noCookies || 'No cookies registered yet.') + '</td></tr>';
        }
      }
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = serializeForm(form);
        body.append('action', 'kncc_save_cookies');
        body.append('nonce', nonce);
        body.append('kncc_lang', strings.knccLang || '');

        fetch(ajaxUrl, { method: 'POST', body: body })
          .then(function (r) {
            var ct = r.headers.get('content-type') || '';
            if (!r.ok || ct.indexOf('application/json') === -1) {
              return r.text().then(function (txt) { throw new Error(txt.substring(0, 200)); });
            }
            return r.json();
          })
          .then(function (res) {
            if (res.success) {
              location.reload();
            } else {
              showNotice(res.data || strings.error, 'error');
            }
          })
          .catch(function (err) { showNotice(err.message || strings.error, 'error'); });
      });
    }

    if (scanBtn) {
      scanBtn.addEventListener('click', function () { runScan(tbody); });
    }

    if (copyPromptBtn) {
      copyPromptBtn.addEventListener('click', function () {
        var cookies = collectCookiesForAiPrompt(tbody);
        if (!cookies.length) {
          showNotice(strings.exportEmpty || strings.error, 'error');
          return;
        }

        var promptText = buildAiPrompt(cookies);

        // Always render the full prompt into a visible, selectable textarea so
        // the user can copy it reliably even on insecure (http) origins where
        // the async Clipboard API is unavailable. Never use window.prompt(): it
        // is single-line and truncates/mangles long multi-line JSON.
        var promptBox = showPromptForCopy(promptText, copyPromptBtn);

        copyPromptToClipboard(promptText, promptBox).then(function () {
          showNotice(strings.promptCopied || strings.saved, 'success');
        }).catch(function () {
          showNotice(strings.promptSelectManually || 'Prompt ready below — select all (Ctrl+A) inside the box and copy (Ctrl+C).', 'error');
        });

        if (importArea) {
          importArea.style.display = '';
        }
      });
    }

    if (importBtn && importTextarea) {
      importBtn.addEventListener('click', function () {
        var raw = (importTextarea.value || '').trim();
        if (!raw) {
          showNotice(strings.invalidJson || strings.error, 'error');
          return;
        }

        try {
          var parsed = JSON.parse(raw);
          var rawCookies = Array.isArray(parsed) ? parsed : (Array.isArray(parsed.cookies) ? parsed.cookies : null);
          if (!rawCookies) {
            showNotice(strings.invalidJson || strings.error, 'error');
            return;
          }

          var normalized = [];
          rawCookies.forEach(function (entry) {
            var item = normalizeCookie(entry);
            if (item) normalized.push(item);
          });

          if (!normalized.length) {
            showNotice(strings.importEmpty || strings.error, 'error');
            return;
          }

          replaceRowsFromCookies(tbody, normalized);
          importTextarea.value = '';

          var saveBody = serializeForm(form);
          saveBody.append('action', 'kncc_save_cookies');
          saveBody.append('nonce', nonce);
          saveBody.append('kncc_lang', strings.knccLang || '');
          fetch(ajaxUrl, { method: 'POST', body: saveBody })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (res.success) {
                window.location.reload();
                return;
              }
              showNotice(res.data || strings.error, 'error');
            })
            .catch(function () {
              showNotice(strings.error, 'error');
            });
        } catch (err) {
          showNotice(strings.invalidJson || strings.error, 'error');
        }
      });
    }
  }

  function normalizeCookie(entry) {
    if (!entry || typeof entry !== 'object') return null;

    var categories = ['necessary', 'analytics', 'marketing', 'preferences'];
    var name = String(entry.name || '').trim();
    if (!name) return null;

    var category = String(entry.category || 'necessary').trim().toLowerCase();
    if (categories.indexOf(category) === -1) category = 'necessary';

    return {
      name: name,
      category: category,
      provider: String(entry.provider || '').trim(),
      duration: String(entry.duration || '').trim(),
      description: String(entry.description || '').trim()
    };
  }

  function collectCookiesFromTable(tbody) {
    var rows = tbody.querySelectorAll('tr[data-index]');
    var result = [];
    rows.forEach(function (row) {
      var nameInput = row.querySelector('input[name*="[name]"]');
      if (!nameInput) return;
      var entry = normalizeCookie({
        name: nameInput.value,
        category: (row.querySelector('select[name*="[category]"]') || {}).value,
        provider: (row.querySelector('input[name*="[provider]"]') || {}).value,
        duration: (row.querySelector('input[name*="[duration]"]') || {}).value,
        description: (row.querySelector('input[name*="[description]"]') || {}).value
      });
      if (entry) result.push(entry);
    });
    return result;
  }

  /**
   * All cookies for the AI prompt, from several sources unioned by name:
   *   1. Complete saved list — the fresh list returned by the latest scan if
   *      available (reflects cookies saved during that scan), otherwise the
   *      page-load list from PHP localize.
   *   2. Latest scan result groups (newly found, may not be saved yet).
   *   3. Current table rows — the user's live edits, which win over the rest.
   * This prevents cookies from being dropped due to scan/save/reload timing.
   */
  function collectCookiesForAiPrompt(tbody) {
    var order = [];
    var byName = {};

    function add(entry, overwrite) {
      var normalized = normalizeCookie(entry);
      if (!normalized) return;
      if (byName[normalized.name]) {
        if (overwrite) byName[normalized.name] = normalized;
      } else {
        order.push(normalized.name);
        byName[normalized.name] = normalized;
      }
    }

    var savedBase = (lastScanResults && lastScanResults.all_saved && lastScanResults.all_saved.length)
      ? lastScanResults.all_saved
      : (cfg.cookies || []);
    savedBase.forEach(function (c) { add(c, false); });

    if (lastScanResults) {
      ['auto_added', 'inferred', 'unknown'].forEach(function (key) {
        (lastScanResults[key] || []).forEach(function (c) { add(c, false); });
      });
    }

    collectCookiesFromTable(tbody).forEach(function (c) { add(c, true); });

    return order.map(function (name) { return byName[name]; });
  }

  /**
   * Render the full AI prompt into a visible, read-only textarea placed after the
   * copy button. Reliable on any origin and free of the truncation/mangling that
   * window.prompt() causes with long multi-line text. Returns the textarea.
   *
   * @param {string} promptText
   * @param {HTMLElement} anchorBtn
   * @param {{id?: string, label?: string}} options
   */
  function showPromptForCopy(promptText, anchorBtn, options) {
    options = options || {};
    var boxId = options.id || 'kncc-ai-prompt-output';
    var labelText = options.label || strings.promptBoxLabel || 'AI prompt (copied to clipboard — or select all and copy manually):';

    var box = document.getElementById(boxId);
    if (!box) {
      var wrap = document.createElement('div');
      wrap.className = 'kncc-ai-prompt-output-wrap';
      wrap.style.marginTop = '10px';

      var label = document.createElement('p');
      label.style.margin = '0 0 4px';
      label.innerHTML = '<strong>' + escHtml(labelText) + '</strong>';
      wrap.appendChild(label);

      box = document.createElement('textarea');
      box.id = boxId;
      box.readOnly = true;
      box.rows = 10;
      box.className = 'large-text code';
      box.style.width = '100%';
      box.style.fontFamily = 'monospace';
      wrap.appendChild(box);

      if (anchorBtn && anchorBtn.parentNode) {
        anchorBtn.parentNode.insertBefore(wrap, anchorBtn.nextSibling);
      } else {
        document.body.appendChild(wrap);
      }
    }
    box.value = promptText;
    return box;
  }

  /**
   * Copy text to the clipboard. Prefers the async Clipboard API (secure origins),
   * then falls back to selecting the visible textarea and execCommand('copy'),
   * which works on http:// where navigator.clipboard is undefined.
   */
  function copyPromptToClipboard(text, textareaEl) {
    return new Promise(function (resolve, reject) {
      function tryExecCommand() {
        try {
          if (textareaEl) {
            textareaEl.focus();
            textareaEl.select();
            textareaEl.setSelectionRange(0, text.length);
          }
          if (document.execCommand('copy')) {
            resolve();
          } else {
            reject();
          }
        } catch (e) {
          reject();
        }
      }

      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(resolve).catch(tryExecCommand);
        return;
      }

      tryExecCommand();
    });
  }

  function replaceRowsFromCookies(tbody, cookies) {
    tbody.innerHTML = '';
    cookieIndex = 0;
    cookies.forEach(function (entry) {
      addRow(tbody, entry.name, entry.category, entry.provider, entry.duration, entry.description);
    });
    if (!cookies.length) {
      tbody.innerHTML = '<tr class="kncc-no-cookies"><td colspan="6">' + escHtml(strings.noCookies || 'No cookies registered yet.') + '</td></tr>';
    }
  }

  function buildAiPrompt(cookies) {
    var locale = strings.locale || 'en_US';
    var payload = JSON.stringify(cookies, null, 2);
    return [
      'You are auditing cookies for a website\'s cookie policy.',
      'Write all descriptions in the language matching this locale: ' + locale + '.',
      '',
      '## Your task',
      '',
      'For each cookie, do two things:',
      '1. Verify the category is correct. Fix it if it is wrong.',
      '2. Rewrite the description in plain, honest language for visitors.',
      '',
      '## Category definitions',
      '',
      '- necessary: required for core site function. Cart, login/session, security, load balancing, and consent-record cookies only.',
      '- preferences (functional): remembers a choice the user made (language, dark/light mode, saved comment details).',
      '- analytics (statistics): measures how visitors use the site.',
      '- marketing: attribution, campaign/source tracking, ad targeting, or cross-site tracking.',
      '',
      'If a cookie\'s real purpose does not match its listed category, change the category. When you change it, add "category_changed": true and "category_reason": "<short reason>" to that cookie object.',
      '',
      '## Rules',
      '',
      '- Return only valid JSON (the same array), no markdown and no extra commentary.',
      '- Do not rename or remove any cookies. Keep the "name" field unchanged.',
      '- The "category" value must be exactly one of: necessary, preferences, analytics, marketing.',
      '- Keep "provider" and "duration" unchanged unless empty and obvious.',
      '- Rewrite each "description" concise (around 8-20 words), factual, and non-legalistic.',
      '- Write all descriptions in the locale language (' + locale + ').',
      '',
      'Cookie JSON:',
      payload
    ].join('\n');
  }

  function addRow(tbody, name, category, provider, duration, description) {
    var noRow = tbody.querySelector('.kncc-no-cookies');
    if (noRow) noRow.remove();

    var existingRows = tbody.querySelectorAll('tr[data-index]');
    for (var r = 0; r < existingRows.length; r++) {
      var nameInput = existingRows[r].querySelector('input[name*="[name]"]');
      if (nameInput && nameInput.value === name) {
        return;
      }
    }

    var i = cookieIndex++;
    var tr = document.createElement('tr');
    tr.setAttribute('data-index', i);
    var cats = ['necessary', 'analytics', 'marketing', 'preferences'];

    function esc(s) { return escHtml(s).replace(/"/g, '&quot;'); }

    tr.innerHTML =
      '<td><input type="text" name="cookies[' + i + '][name]" value="' + esc(name) + '" class="regular-text" /></td>' +
      '<td><select name="cookies[' + i + '][category]">' +
        cats.map(function (c) {
          return '<option value="' + c + '"' + (c === (category || 'necessary') ? ' selected' : '') + '>' + c.charAt(0).toUpperCase() + c.slice(1) + '</option>';
        }).join('') +
      '</select></td>' +
      '<td><input type="text" name="cookies[' + i + '][provider]" value="' + esc(provider) + '" /></td>' +
      '<td><input type="text" name="cookies[' + i + '][duration]" value="' + esc(duration) + '" style="width:100px;" /></td>' +
      '<td><input type="text" name="cookies[' + i + '][description]" value="' + esc(description) + '" class="large-text" /></td>' +
      '<td><button type="button" class="button kncc-remove-cookie" title="' + escHtml(strings.removeTitle || 'Remove') + '">&times;</button></td>';

    tbody.appendChild(tr);
  }

  /* ── Cookie Scanner ── */

  var scanInProgress = false;
  var lastScanResults = null;
  var scanConsentSnapshot = null;
  // Two iframe loads (consent seed + collect) plus poll window.
  var SCAN_TIMEOUT_MS = 40000;
  var SCAN_POLL_INTERVAL_MS = 500;
  var SCAN_POLL_MAX_MS = 8000;

  function parseCookieNames(raw) {
    var names = [];
    if (!raw) return names;
    raw.split(';').forEach(function (pair) {
      var name = pair.split('=')[0].trim();
      if (name && names.indexOf(name) === -1) names.push(name);
    });
    return names;
  }

  function mergeCookieNames(target, source) {
    source.forEach(function (name) {
      if (target.indexOf(name) === -1) target.push(name);
    });
    return target;
  }

  function scanFrameUrl(pass) {
    var base = cfg.homeUrl || '/';
    var sep = base.indexOf('?') === -1 ? '?' : '&';
    return base + sep + 'kncc_scan=' + Date.now() + '&kncc_pass=' + pass;
  }

  /**
   * The scan iframe loads the site on the same host as wp-admin, so the accept-all
   * cookies it seeds land in the administrator's own cookie jar. These helpers
   * snapshot the admin's real consent before a scan and put it back afterwards, so
   * running Scan Cookies does not silently grant the admin full consent.
   */
  function readAllCookies() {
    var map = {};
    document.cookie.split(';').forEach(function (pair) {
      var idx = pair.indexOf('=');
      if (idx === -1) return;
      var name = pair.slice(0, idx).trim();
      if (name) map[name] = pair.slice(idx + 1);
    });
    return map;
  }

  function isScanManagedCookie(name) {
    return name === 'kncc_consent' || name.indexOf('wp_consent_') === 0;
  }

  function snapshotConsentCookies() {
    var all = readAllCookies();
    var snapshot = {};
    Object.keys(all).forEach(function (name) {
      if (isScanManagedCookie(name)) snapshot[name] = all[name];
    });
    return snapshot;
  }

  function restoreConsentCookies(snapshot) {
    if (!snapshot) return;

    var secure = location.protocol === 'https:' ? ';secure' : '';

    // Drop anything the scan created that was not there before.
    Object.keys(readAllCookies()).forEach(function (name) {
      if (isScanManagedCookie(name) && !Object.prototype.hasOwnProperty.call(snapshot, name)) {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
      }
    });

    // Original expiry dates are not readable from document.cookie, so restore with
    // the lifetimes the plugin normally uses.
    Object.keys(snapshot).forEach(function (name) {
      var days = name === 'kncc_consent' ? 365 : 30;
      var expires = ';expires=' + new Date(Date.now() + days * 864e5).toUTCString();
      document.cookie = name + '=' + snapshot[name] + ';path=/;SameSite=Lax' + secure + expires;
    });
  }

  function grantScanConsent(doc) {
    if (!doc) return;
    var consent = encodeURIComponent(JSON.stringify({
      necessary: true,
      analytics: true,
      marketing: true,
      preferences: true,
      choice: 'accept',
      timestamp: new Date().toISOString()
    }));
    var secure = doc.location && doc.location.protocol === 'https:' ? ';Secure' : '';
    doc.cookie = 'kncc_consent=' + consent + ';path=/;SameSite=Lax' + secure;

    // Bridge cookies are normally written on the next page load (early wp_head sync /
    // server sync). Write them here too so the collect pass always sees them even
    // if a browser skips a same-URL reload.
    var win = null;
    try { win = doc.defaultView || doc.parentWindow; } catch (e) { win = null; }
    var prefix = 'wp_consent';
    var days = 30;
    try {
      if (win && win.consent_api && win.consent_api.cookie_prefix) {
        prefix = win.consent_api.cookie_prefix;
      }
      if (win && win.consent_api && win.consent_api.cookie_expiration) {
        days = win.consent_api.cookie_expiration;
      }
    } catch (e2) {}
    var exp = ';expires=' + new Date(Date.now() + days * 864e5).toUTCString();
    var sec = doc.location && doc.location.protocol === 'https:' ? ';secure' : '';
    ['functional', 'statistics', 'statistics-anonymous', 'marketing', 'preferences'].forEach(function (cat) {
      doc.cookie = prefix + '_' + cat + '=allow' + sec + exp + ';path=/';
    });
  }

  function activateScanScripts(doc) {
    if (!doc) return;
    var blocked = doc.querySelectorAll('script[type="text/plain"][data-kncc-category]');
    blocked.forEach(function (el) {
      var newScript = doc.createElement('script');
      newScript.type = 'text/javascript';
      if (el.src) {
        newScript.src = el.src;
        if (el.hasAttribute('async')) newScript.async = true;
        if (el.hasAttribute('defer')) newScript.defer = true;
      } else {
        newScript.textContent = el.textContent;
      }
      el.parentNode.insertBefore(newScript, el.nextSibling);
    });
  }

  function collectScanCookies(iframe) {
    var found = [];
    try {
      var doc = iframe.contentDocument || iframe.contentWindow.document;
      mergeCookieNames(found, parseCookieNames(doc.cookie || ''));
    } catch (e) {
      // cross-origin — fall through to admin document.cookie
    }
    mergeCookieNames(found, parseCookieNames(document.cookie || ''));
    return found;
  }

  function postScanResults(found, tbody, resultsEl, iframe, scanBtn) {
    var body = new URLSearchParams();
    body.append('action', 'kncc_process_scan');
    body.append('nonce', nonce);
    body.append('kncc_lang', strings.knccLang || '');
    found.forEach(function (n) { body.append('found[]', n); });

    fetch(ajaxUrl, { method: 'POST', body: body })
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('application/json') === -1) {
          return r.text().then(function (txt) {
            throw new Error('Server returned HTTP ' + r.status + '. Response: ' + txt.substring(0, 300));
          });
        }
        return r.json();
      })
      .then(function (res) {
        scanCleanup(iframe, scanBtn);

        if (!res.success) {
          showScanError(resultsEl, res.data || strings.error);
          return;
        }

        renderScanResults(res.data, tbody, resultsEl);
      })
      .catch(function (err) {
        scanCleanup(iframe, scanBtn);
        showScanError(resultsEl, err.message || strings.error);
      });
  }

  function renderScanGroup(title, items, iconClass, iconColor, extraClass, renderRow) {
    if (!items.length) return '';
    var html = '<div class="kncc-scan-group' + (extraClass ? ' ' + extraClass : '') + '">';
    html += '<div class="kncc-scan-group__header">';
    html += '<span class="dashicons ' + iconClass + '" style="color:' + iconColor + ';margin-right:4px;"></span> ';
    html += '<strong>' + escHtml(title) + '</strong> <span class="kncc-scan-group__count">(' + items.length + ')</span>';
    html += '</div>';
    html += '<table class="kncc-scan-group__table"><tbody>';
    items.forEach(function (item) {
      html += renderRow(item);
    });
    html += '</tbody></table></div>';
    return html;
  }

  function renderScanResults(data, tbody, resultsEl) {
    lastScanResults = data || null;
    var autoAdded = data.auto_added || [];
    var inferred = data.inferred || [];
    var unknown = data.unknown || [];
    var filtered = data.filtered || [];
    var stale = data.stale || [];

    if (!autoAdded.length && !inferred.length && !unknown.length && !filtered.length && !stale.length) {
      resultsEl.innerHTML = '<div class="kncc-scan-alldone"><span class="dashicons dashicons-yes-alt" style="color:#059669;margin-right:4px;"></span> ' + (strings.allDone || 'All cookies are done! No new or incomplete cookies found.') + '</div>';
      return;
    }

    var html = '<h4>' + strings.scanDone + '</h4>';

    if (autoAdded.length) {
      var groups = {};
      autoAdded.forEach(function (c) {
        var key = c.provider || 'Other';
        if (!groups[key]) groups[key] = [];
        groups[key].push(c);
      });

      html += '<div class="kncc-scan-auto">';
      html += '<p><strong>' + (strings.knownAdded || '%d known cookie(s) added automatically:').replace('%d', autoAdded.length) + '</strong></p>';
      Object.keys(groups).sort().forEach(function (provider) {
        html += renderScanGroup(provider, groups[provider], 'dashicons-yes-alt', '#059669', 'kncc-scan-group--auto', function (c) {
          return '<tr><td><code>' + escHtml(c.name) + '</code></td>'
            + '<td class="kncc-scan-cat kncc-scan-cat--' + escHtml(c.category) + '">' + escHtml(c.category) + '</td>'
            + '<td class="kncc-scan-desc">' + escHtml(c.description) + '</td></tr>';
        });
      });
      html += '</div>';

      autoAdded.forEach(function (c) {
        addRow(tbody, c.name, c.category, c.provider, c.duration, c.description);
      });
    }

    if (inferred.length) {
      html += '<div class="kncc-scan-inferred">';
      html += '<p><strong>' + (strings.inferredAdded || '%d cookie(s) inferred from detected scripts on your site:').replace('%d', inferred.length) + '</strong></p>';
      html += '<p class="description">' + escHtml(strings.inferredNote || 'These cookies are expected from tracking scripts on your site, even when blocked until consent.') + '</p>';
      html += renderScanGroup('Inferred', inferred, 'dashicons-admin-site-alt3', '#2563eb', 'kncc-scan-group--inferred', function (c) {
        return '<tr><td><code>' + escHtml(c.name) + '</code></td>'
          + '<td class="kncc-scan-cat kncc-scan-cat--' + escHtml(c.category) + '">' + escHtml(c.category) + '</td>'
          + '<td class="kncc-scan-desc">' + escHtml(c.description) + '</td></tr>';
      });
      html += '</div>';

      inferred.forEach(function (c) {
        addRow(tbody, c.name, c.category, c.provider, c.duration, c.description);
      });
    }

    if (unknown.length) {
      html += '<div class="kncc-scan-unknown">';
      html += '<p><strong>' + (strings.unknownAdded || '%d unknown cookie(s) added — please set their category and description below.').replace('%d', unknown.length) + '</strong></p>';
      html += renderScanGroup(strings.unknownLabel || 'Unknown', unknown, 'dashicons-warning', '#d97706', '', function (c) {
        return '<tr><td><code>' + escHtml(c.name) + '</code></td>'
          + '<td class="kncc-scan-desc">' + escHtml(strings.unknownDesc || 'Added — categorize and describe in the table below') + '</td></tr>';
      });
      html += '</div>';

      unknown.forEach(function (c) {
        addRow(tbody, c.name, c.category, c.provider, c.duration, c.description);
      });
    }

    if (filtered.length) {
      html += '<div class="kncc-scan-filtered">';
      html += '<p><strong>' + (strings.filteredSkipped || '%d browser cookie(s) skipped — found in your browser but no matching script on this site:').replace('%d', filtered.length) + '</strong></p>';
      html += renderScanGroup('Filtered', filtered, 'dashicons-hidden', '#6b7280', 'kncc-scan-group--filtered', function (c) {
        return '<tr><td><code>' + escHtml(c.name) + '</code></td>'
          + '<td class="kncc-scan-desc">' + escHtml(c.provider || '') + '</td></tr>';
      });
      html += '</div>';
    }

    if (stale.length) {
      html += '<div class="kncc-scan-stale" id="kncc-scan-stale">';
      html += '<p><strong>' + escHtml(strings.staleTitle || 'Possibly removed') + ' (' + stale.length + ')</strong></p>';
      html += '<p class="description">' + escHtml(strings.staleDesc || 'These cookies are still in your list, but their tracking scripts were not found on the homepage.') + '</p>';
      html += '<table class="kncc-scan-group__table"><tbody>';
      stale.forEach(function (c) {
        html += '<tr>'
          + '<td style="width:28px;"><input type="checkbox" class="kncc-stale-check" value="' + escHtml(c.name) + '" checked /></td>'
          + '<td><code>' + escHtml(c.name) + '</code></td>'
          + '<td>' + escHtml(c.provider || '') + '</td>'
          + '<td class="kncc-scan-cat kncc-scan-cat--' + escHtml(c.category || '') + '">' + escHtml(c.category || '') + '</td>'
          + '<td class="kncc-scan-desc">' + escHtml(c.reason || '') + '</td>'
          + '</tr>';
      });
      html += '</tbody></table>';
      html += '<p><button type="button" class="button" id="kncc-remove-stale-btn">' + escHtml(strings.removeStaleBtn || 'Remove selected cookies') + '</button></p>';
      html += '</div>';
    }

    if (!unknown.length) {
      var allHaveDesc = true;
      var allRows = tbody.querySelectorAll('tr[data-index]');
      allRows.forEach(function (row) {
        var descInput = row.querySelector('input[name*="[description]"]');
        if (descInput && !descInput.value.trim()) allHaveDesc = false;
      });
      if (allHaveDesc) {
        html += '<div class="kncc-scan-alldone"><span class="dashicons dashicons-yes-alt" style="color:#059669;margin-right:4px;"></span> ' + (strings.allDone || 'All cookies are done! Every cookie has a description.') + '</div>';
      }
    }

    resultsEl.innerHTML = html;

    var removeStaleBtn = document.getElementById('kncc-remove-stale-btn');
    if (removeStaleBtn) {
      removeStaleBtn.addEventListener('click', function () {
        removeStaleCookies(tbody, resultsEl);
      });
    }

    if (unknown.length) {
      var helperEl = document.getElementById('kncc-ai-helper');
      if (helperEl) helperEl.style.display = '';
    } else if (autoAdded.length || inferred.length) {
      var helperAfterScan = document.getElementById('kncc-ai-helper');
      if (helperAfterScan) helperAfterScan.style.display = '';
    }
  }

  function removeRowsByName(tbody, names) {
    var lookup = {};
    names.forEach(function (name) { lookup[name] = true; });
    tbody.querySelectorAll('tr[data-index]').forEach(function (row) {
      var nameInput = row.querySelector('input[name*="[name]"]');
      if (nameInput && lookup[nameInput.value]) {
        row.remove();
      }
    });
    if (!tbody.querySelector('tr[data-index]')) {
      tbody.innerHTML = '<tr class="kncc-no-cookies"><td colspan="6">' + escHtml(strings.noCookies || 'No cookies registered yet.') + '</td></tr>';
    }
  }

  function removeStaleCookies(tbody, resultsEl) {
    var checks = resultsEl.querySelectorAll('.kncc-stale-check:checked');
    var names = [];
    checks.forEach(function (el) {
      if (el.value) names.push(el.value);
    });

    if (!names.length) {
      showNotice(strings.error || 'An error occurred.', 'error');
      return;
    }

    var confirmMsg = (strings.confirmStaleRemove || 'Remove %d cookie(s) from the list? This cannot be undone automatically.').replace('%d', names.length);
    if (!confirm(confirmMsg)) {
      return;
    }

    var body = new URLSearchParams();
    body.append('action', 'kncc_remove_cookies');
    body.append('nonce', nonce);
    names.forEach(function (name) { body.append('names[]', name); });

    fetch(ajaxUrl, { method: 'POST', body: body })
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('application/json') === -1) {
          return r.text().then(function (txt) { throw new Error(txt.substring(0, 200)); });
        }
        return r.json();
      })
      .then(function (res) {
        if (!res.success) {
          showNotice(res.data || strings.error, 'error');
          return;
        }

        var removed = (res.data && res.data.removed) ? res.data.removed : names;
        removeRowsByName(tbody, removed);

        var staleEl = document.getElementById('kncc-scan-stale');
        if (staleEl) {
          staleEl.remove();
        }

        showNotice((res.data && res.data.message) || strings.staleRemoved || 'Selected cookies removed from the list.', 'success');
      })
      .catch(function (err) {
        showNotice(err.message || strings.error, 'error');
      });
  }

  function scanCleanup(iframe, scanBtn) {
    scanInProgress = false;
    iframe.onload = null;
    iframe.onerror = null;
    try { iframe.src = 'about:blank'; } catch (e) {}
    restoreConsentCookies(scanConsentSnapshot);
    scanConsentSnapshot = null;
    if (scanBtn) {
      scanBtn.disabled = false;
      scanBtn.textContent = strings.scanBtn || 'Scan Cookies';
    }
  }

  function showScanError(resultsEl, message) {
    resultsEl.innerHTML =
      '<div style="color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;">' +
      '<strong>Scan failed:</strong> ' + escHtml(message) + '</div>';
  }

  function runScan(tbody) {
    if (scanInProgress) return;

    var resultsEl = document.getElementById('kncc-scan-results');
    var iframe = document.getElementById('kncc-scan-iframe');
    var scanBtn = document.getElementById('kncc-scan-btn');
    if (!resultsEl || !iframe) return;

    scanInProgress = true;
    scanConsentSnapshot = snapshotConsentCookies();
    if (scanBtn) {
      scanBtn.disabled = true;
      scanBtn.textContent = strings.scanning || 'Scanning...';
    }
    resultsEl.innerHTML = '<span class="kncc-scan-spinner"></span> ' + strings.scanning;
    resultsEl.style.display = '';

    var pass = 0;
    var timer = setTimeout(function () {
      scanCleanup(iframe, scanBtn);
      showScanError(resultsEl, 'Scan timed out after ' + (SCAN_TIMEOUT_MS / 1000) + ' seconds. Your site may be blocking iframes or taking too long to load.');
    }, SCAN_TIMEOUT_MS);

    iframe.onerror = function () {
      clearTimeout(timer);
      scanCleanup(iframe, scanBtn);
      showScanError(resultsEl, 'Could not load the site for scanning. Check that your homepage is accessible.');
    };

    function startCollectPhase(doc) {
      if (doc) {
        grantScanConsent(doc);
        activateScanScripts(doc);
        if (typeof doc.defaultView !== 'undefined' && doc.defaultView && doc.defaultView.dispatchEvent) {
          doc.defaultView.dispatchEvent(new Event('DOMContentLoaded'));
        }
      }

      var elapsed = 0;
      var pollTimer = setInterval(function () {
        elapsed += SCAN_POLL_INTERVAL_MS;
        if (elapsed >= SCAN_POLL_MAX_MS) {
          clearInterval(pollTimer);
          clearTimeout(timer);
          try { iframe.src = 'about:blank'; } catch (e) {}
          postScanResults(collectScanCookies(iframe), tbody, resultsEl, iframe, scanBtn);
        }
      }, SCAN_POLL_INTERVAL_MS);

      // Re-activate after a short delay so consent.js / delayed blockers can run.
      setTimeout(function () {
        if (doc) {
          activateScanScripts(doc);
        }
      }, 100);
    }

    function onFrameLoad() {
      var doc = null;
      try {
        doc = iframe.contentDocument || iframe.contentWindow.document;
      } catch (e) {
        doc = null;
      }

      pass += 1;

      // Pass 1 only seeds kncc_consent (+ wp_consent_*). Pass 2 reloads with those
      // cookies present so WP Consent API sync and consent-gated trackers fire as
      // they would for a returning visitor — one Scan click, no manual re-scan.
      if (pass === 1) {
        if (doc) {
          grantScanConsent(doc);
        }
        iframe.onload = onFrameLoad;
        iframe.src = scanFrameUrl(2);
        return;
      }

      iframe.onload = null;
      iframe.onerror = null;
      startCollectPhase(doc);
    }

    iframe.onload = onFrameLoad;
    iframe.src = scanFrameUrl(1);
  }

  /* ── Consent Log export/purge ── */

  function initConsentLog() {
    var exportBtn = document.getElementById('kncc-export-log-btn');
    var purgeBtn = document.getElementById('kncc-purge-log-btn');

    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        var body = new URLSearchParams();
        body.append('action', 'kncc_export_consent_log');
        body.append('nonce', nonce);

        fetch(ajaxUrl, { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.success) {
              showNotice(res.data || strings.error, 'error');
              return;
            }
            var blob = new Blob([res.data.csv], { type: 'text/csv;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'consent-log-' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
          })
          .catch(function (err) { showNotice(err.message || strings.error, 'error'); });
      });
    }

    if (purgeBtn) {
      purgeBtn.addEventListener('click', function () {
        if (!confirm(strings.confirmPurge || 'This will permanently delete all consent log records and statistics. Continue?')) {
          return;
        }
        var body = new URLSearchParams();
        body.append('action', 'kncc_purge_consent_log');
        body.append('nonce', nonce);

        fetch(ajaxUrl, { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) {
              showNotice(strings.purged || 'Purged.', 'success');
              window.location.reload();
            } else {
              showNotice(res.data || strings.error, 'error');
            }
          })
          .catch(function (err) { showNotice(err.message || strings.error, 'error'); });
      });
    }
  }

  /* ── AI Prompt for Policy Page Content ── */

  function initPolicyAiHelper() {
    var copyBtn = document.getElementById('kncc-copy-policy-prompt-btn');
    if (!copyBtn) return;

    copyBtn.addEventListener('click', function () {
      var data = window.knccPolicyPromptData || {};
      var prompt = buildPolicyPrompt(data);

      var promptBox = showPromptForCopy(prompt, copyBtn, {
        id: 'kncc-policy-prompt-output',
        label: strings.policyPromptBoxLabel || strings.promptBoxLabel
      });

      copyPromptToClipboard(prompt, promptBox).then(function () {
        showNotice(strings.policyCopied || strings.promptCopied || 'Prompt copied!', 'success');
      }).catch(function () {
        showNotice(strings.promptSelectManually || 'Prompt ready below — select all (Ctrl+A) inside the box and copy (Ctrl+C).', 'error');
      });
    });
  }

  /* ── CSS template copy (Documentations tab) ── */

  function initCssTemplateCopy() {
    var copyBtn = document.getElementById('kncc-copy-css-template-btn');
    var codeEl = document.getElementById('kncc-css-template');
    var feedbackEl = document.getElementById('kncc-css-template-feedback');
    if (!copyBtn || !codeEl) return;

    var hideTimer = null;
    function showFeedback() {
      if (!feedbackEl) return;
      feedbackEl.style.display = '';
      if (hideTimer) clearTimeout(hideTimer);
      hideTimer = setTimeout(function () {
        feedbackEl.style.display = 'none';
      }, 2500);
    }

    copyBtn.addEventListener('click', function () {
      var text = codeEl.textContent || codeEl.innerText || '';
      // window.prompt() is single-line and mangles the multi-line template, so fall
      // back to the same selectable textarea the AI prompts use.
      var promptBox = showPromptForCopy(text, copyBtn, {
        id: 'kncc-css-template-output',
        label: strings.promptBoxLabel
      });

      copyPromptToClipboard(text, promptBox)
        .then(showFeedback)
        .catch(function () {
          showNotice(strings.promptSelectManually || 'Select all (Ctrl+A) inside the box and copy (Ctrl+C).', 'error');
        });
    });
  }

  /* ── Generic "copy this text" buttons ── */

  function initCopyTextButtons() {
    document.querySelectorAll('.kncc-copy-text-btn').forEach(function (btn) {
      var text = btn.getAttribute('data-kncc-copy') || '';
      if (!text) return;

      btn.addEventListener('click', function () {
        var original = btn.textContent;
        var copiedLabel = btn.getAttribute('data-kncc-copied-label') || original;

        copyPromptToClipboard(text, showPromptForCopy(text, btn, {
          id: btn.id + '-output',
          label: strings.promptBoxLabel
        })).then(function () {
          // On success the fallback textarea is redundant noise — remove it.
          var box = document.getElementById(btn.id + '-output');
          if (box && box.parentNode) box.parentNode.remove();
          btn.textContent = copiedLabel;
          setTimeout(function () { btn.textContent = original; }, 2000);
        }).catch(function () {
          showNotice(strings.promptSelectManually || 'Select all (Ctrl+A) inside the box and copy (Ctrl+C).', 'error');
        });
      });
    });
  }

  function buildPolicyPrompt(data) {
    var lines = [
      'Generate a complete cookie and privacy policy page for a website.',
      'Write everything in the language matching the locale below.',
      '',
      'Site name: ' + (data.siteName || 'Unknown'),
      'Site URL: ' + (data.siteUrl || ''),
      'Admin email: ' + (data.adminEmail || ''),
      'Locale: ' + (data.locale || 'en_US'),
      ''
    ];

    if (data.categories && data.categories.length) {
      lines.push('Cookie categories used on this site:');
      data.categories.forEach(function (c) { lines.push('- ' + c); });
      lines.push('');
    }

    lines.push('The page should include the following sections:');
    lines.push('1. Register and data protection notice — what kind of register this is and under what law (e.g. GDPR)');
    lines.push('2. Data controller — company/person name, contact email');
    lines.push('3. Register name — what the register is called');
    lines.push('4. Legal basis and purpose of processing personal data — explain why data is collected');
    lines.push('5. Data content — what kind of data is stored (name, email, IP, etc.)');
    lines.push('6. Data sources — where the data comes from (website forms, cookies, analytics, etc.)');
    lines.push('7. Data retention — how long data is stored');
    lines.push('8. Data disclosure and transfer — whether data is shared with third parties');
    lines.push('9. Rights of the data subject — right to access, rectify, delete, restrict processing, object, and data portability');
    lines.push('10. Cookies — DO NOT list individual cookies. Instead, include this exact WordPress shortcode on its own line: [kncc_cookie_policy]');
    lines.push('    This shortcode will automatically render the full cookie declaration table.');
    lines.push('11. Consent management — explain that this site uses a consent banner and visitors can change their preferences at any time');
    lines.push('');
    lines.push('Rules:');
    lines.push('- Write in the language matching the locale (' + (data.locale || 'en_US') + ').');
    lines.push('- Tone: professional, clear, not overly legalistic.');
    lines.push('- DO NOT use placeholders like [Company name] or [Email]. If you need information that is not provided above (such as the company name, contact person, business ID, or address), ASK me for it before generating the page. Do not guess or leave blanks.');
    lines.push('- In the Cookies section (section 10), output ONLY the shortcode [kncc_cookie_policy] — the plugin generates the cookie table automatically.');
    lines.push('- Output plain HTML that can be pasted directly into a WordPress page editor.');
    lines.push('- Do NOT wrap the output in markdown code fences or add any commentary outside the HTML.');

    return lines.join('\n');
  }

  /* ── Live banner preview (General tab) ── */

  function initBannerPreview() {
    var form = document.getElementById('kncc-general-form');
    var preview = document.getElementById('kncc-preview');
    var styleEl = document.getElementById('kncc-preview-vars');
    var host = document.getElementById('kncc-preview-host');
    var openBtn = document.getElementById('kncc-open-preview');
    if (!form || !preview || !styleEl) return;

    function openPreview() {
      if (!host) return;
      host.style.display = '';
      host.setAttribute('aria-hidden', 'false');
    }
    function closePreview() {
      if (!host) return;
      host.style.display = 'none';
      host.setAttribute('aria-hidden', 'true');
    }
    if (openBtn) openBtn.addEventListener('click', openPreview);
    if (host) {
      host.addEventListener('click', function (e) {
        if (e.target.hasAttribute('data-kncc-preview-close')) closePreview();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && host.style.display !== 'none') closePreview();
      });
    }

    var COLOR_FIELDS = [
      'banner_bg_color', 'heading_color', 'body_color',
      'primary_color', 'primary_text_color',
      'secondary_color', 'secondary_text_color'
    ];
    var TEXT_FIELDS = ['heading_text', 'description_text', 'accept_text', 'reject_text', 'customize_text', 'save_text', 'close_text'];
    ['necessary', 'analytics', 'marketing', 'preferences'].forEach(function (cat) {
      TEXT_FIELDS.push('category_name_' + cat, 'category_desc_' + cat);
      if (cat !== 'necessary') TEXT_FIELDS.push('empty_note_' + cat);
    });

    function darken(hex, amount) {
      if (!hex || hex.charAt(0) !== '#') return hex;
      var h = hex.length === 4
        ? '#' + hex[1]+hex[1] + hex[2]+hex[2] + hex[3]+hex[3]
        : hex;
      var diff = Math.round(255 * Math.max(0, Math.min(100, amount)) / 100);
      var r = Math.max(0, parseInt(h.substr(1,2),16) - diff);
      var g = Math.max(0, parseInt(h.substr(3,2),16) - diff);
      var b = Math.max(0, parseInt(h.substr(5,2),16) - diff);
      return '#' + [r,g,b].map(function(n){ return n.toString(16).padStart(2,'0'); }).join('');
    }

    function readColor(name) {
      var input = form.querySelector('[name="' + name + '"]');
      if (!input) return '';
      return input.value || input.getAttribute('data-default-color') || '';
    }

    function rebuildVars() {
      var primary = readColor('primary_color');
      var secondary = readColor('secondary_color');
      var vars = '#kncc-preview-host{' +
        '--kncc-banner-bg:' + readColor('banner_bg_color') + ';' +
        '--kncc-heading:' + readColor('heading_color') + ';' +
        '--kncc-body:' + readColor('body_color') + ';' +
        '--kncc-primary:' + primary + ';' +
        '--kncc-primary-hover:' + darken(primary, 15) + ';' +
        '--kncc-primary-text:' + readColor('primary_text_color') + ';' +
        '--kncc-secondary:' + secondary + ';' +
        '--kncc-secondary-hover:' + darken(secondary, 8) + ';' +
        '--kncc-secondary-text:' + readColor('secondary_text_color') + ';' +
      '}';
      styleEl.textContent = vars;
    }

    function syncTextField(name) {
      var input = form.querySelector('[name="' + name + '"]');
      if (!input) return;
      var value = input.value || input.placeholder || '';
      if (name === 'close_text') {
        var closeBtn = preview.querySelector('.kncc-banner-close');
        if (closeBtn) closeBtn.setAttribute('aria-label', value);
        return;
      }
      var target = preview.querySelector('.kncc-preview-' + name);
      if (target) target.textContent = value;
    }

    function syncBannerStyle() {
      var styleSel = form.querySelector('[name="banner_style"]');
      if (!styleSel) return;
      preview.classList.remove('kncc-banner--bar', 'kncc-banner--modal', 'kncc-banner--corner');
      preview.classList.add('kncc-banner--' + styleSel.value);
    }

    function syncEmptyCategoryMode() {
      var sel = form.querySelector('[name="empty_category_mode"]');
      if (!sel) return;
      var mode = sel.value === 'disable' ? 'disable' : 'hide';
      var emptyRows = preview.querySelectorAll('[data-kncc-empty="1"]');
      emptyRows.forEach(function (row) {
        var check = row.querySelector('.kncc-category__check');
        if (mode === 'hide') {
          row.style.display = 'none';
          row.classList.remove('kncc-category--empty');
          if (check) check.disabled = false;
        } else {
          row.style.display = '';
          row.classList.add('kncc-category--empty');
          if (check) {
            check.disabled = true;
            check.checked = false;
          }
        }
      });
    }

    function syncTypography() {
      var sel = form.querySelector('[name="text_style_mode"]');
      if (!sel) return;
      var mode = sel.value === 'plugin' ? 'plugin' : 'theme';
      preview.classList.remove('kncc-typography--theme', 'kncc-typography--plugin');
      preview.classList.add('kncc-typography--' + mode);
      var colorsRow = document.getElementById('kncc-colors-row');
      if (colorsRow) colorsRow.style.display = mode === 'plugin' ? '' : 'none';

      // Theme style depends on the frontend theme's CSS, which doesn't load in /wp-admin/.
      // Disable the preview button and swap the help text accordingly.
      var helpPlugin = document.querySelector('.kncc-preview-help-plugin');
      var helpTheme = document.querySelector('.kncc-preview-help-theme');
      if (openBtn) openBtn.disabled = (mode !== 'plugin');
      if (helpPlugin) helpPlugin.style.display = mode === 'plugin' ? '' : 'none';
      if (helpTheme) helpTheme.style.display = mode === 'plugin' ? 'none' : '';
      if (mode !== 'plugin' && host && host.style.display !== 'none') {
        closePreview();
      }
    }

    if (window.jQuery && jQuery.fn.wpColorPicker) {
      jQuery('.kncc-color-field').wpColorPicker({
        change: function () {
          // Defer so .val() reflects the new color before reading.
          setTimeout(rebuildVars, 0);
        },
        clear: function () {
          setTimeout(rebuildVars, 0);
        }
      });
    }

    TEXT_FIELDS.forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      if (!input) return;
      input.addEventListener('input', function () { syncTextField(name); });
    });

    var styleSel = form.querySelector('[name="banner_style"]');
    if (styleSel) styleSel.addEventListener('change', syncBannerStyle);

    var typoSel = form.querySelector('[name="text_style_mode"]');
    if (typoSel) typoSel.addEventListener('change', syncTypography);

    var emptyModeSel = form.querySelector('[name="empty_category_mode"]');
    if (emptyModeSel) emptyModeSel.addEventListener('change', syncEmptyCategoryMode);

    // The frontend banner has consent.js wiring up the Customize button to toggle the details panel.
    // consent.js doesn't load in admin, so wire it manually here so the preview behaves like the frontend.
    var customizeBtn = preview.querySelector('.kncc-preview-customize_text');
    var detailsEl = preview.querySelector('.kncc-details');
    if (customizeBtn && detailsEl) {
      customizeBtn.addEventListener('click', function () {
        var hidden = detailsEl.style.display === 'none';
        detailsEl.style.display = hidden ? '' : 'none';
      });
    }
  }

  /* ── Script Blocking settings ── */

  function initBlockingTab() {
    var form = document.getElementById('kncc-blocking-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = new URLSearchParams();
      body.append('action', 'kncc_save_blocking_settings');
      body.append('nonce', nonce);
      if (form.querySelector('[name="block_html_scripts"]') && form.querySelector('[name="block_html_scripts"]').checked) {
        body.append('block_html_scripts', '1');
      }
      // Sent as '1' or '0' every time, so the server can tell "unchecked" apart from a
      // request made by an older version of this form that did not have the field.
      var blockIframes = form.querySelector('[name="block_iframes"]');
      if (blockIframes) {
        body.append('block_iframes', blockIframes.checked ? '1' : '0');
      }
      // Always sent, so saving with every row removed clears the rules.
      body.append('url_rules_present', '1');
      form.querySelectorAll('.kncc-url-rule').forEach(function (row, i) {
        var pattern = row.querySelector('.kncc-url-rule__pattern').value.trim();
        if (!pattern) return;
        body.append('url_rules[' + i + '][pattern]', pattern);
        body.append('url_rules[' + i + '][category]', row.querySelector('.kncc-url-rule__category').value);
        if (row.querySelector('.kncc-url-rule__placeholder').checked) {
          body.append('url_rules[' + i + '][placeholder]', '1');
        }
      });

      fetch(ajaxUrl, { method: 'POST', body: body })
        .then(function (r) {
          var ct = r.headers.get('content-type') || '';
          if (!r.ok || ct.indexOf('application/json') === -1) {
            return r.text().then(function (txt) { throw new Error(txt.substring(0, 200)); });
          }
          return r.json();
        })
        .then(function (res) {
          if (res.success) {
            showNotice(res.data && res.data.message ? res.data.message : (strings.blockingSaved || strings.saved), 'success');
          } else {
            showNotice(res.data || strings.error, 'error');
          }
        })
        .catch(function (err) { showNotice(err.message || strings.error, 'error'); });
    });

    // "Block by address" rows: add from the <template>, remove with the row's button.
    var rulesBody = document.getElementById('kncc-url-rules-body');
    var ruleTemplate = document.getElementById('kncc-url-rule-template');
    var addRule = document.getElementById('kncc-add-url-rule');
    if (rulesBody && ruleTemplate && addRule) {
      addRule.addEventListener('click', function () {
        var row = ruleTemplate.content.firstElementChild.cloneNode(true);
        rulesBody.appendChild(row);
        row.querySelector('.kncc-url-rule__pattern').focus();
      });
      rulesBody.addEventListener('click', function (e) {
        var remove = e.target.closest('.kncc-url-rule__remove');
        if (!remove) return;
        remove.closest('.kncc-url-rule').remove();
        addRule.focus();
      });
    }
  }

  /* ── Integration tab: the defaults preview follows the Advanced Consent Mode checkbox ── */

  function initConsentModePreview() {
    var toggle = document.querySelector('#kncc-integration-form [name="consent_mode_advanced"]');
    if (!toggle) return;

    function sync() {
      var mode = toggle.checked ? 'advanced' : 'basic';
      document.querySelectorAll('[data-kncc-preview]').forEach(function (el) {
        el.hidden = el.getAttribute('data-kncc-preview') !== mode;
      });
    }

    toggle.addEventListener('change', sync);
    sync();
  }

  /* ── Help tooltips: Escape closes the one opened from the keyboard ── */

  function initTooltips() {
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var active = document.activeElement;
      if (active && active.closest && active.closest('.kncc-tooltip')) {
        active.blur();
      }
    });
  }

  /* ── Init ── */

  function init() {
    initSettingsForms();
    initCookiesTab();
    initBlockingTab();
    initConsentModePreview();
    initTooltips();
    initConsentLog();
    initPolicyAiHelper();
    initCssTemplateCopy();
    initCopyTextButtons();
    initBannerPreview();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
