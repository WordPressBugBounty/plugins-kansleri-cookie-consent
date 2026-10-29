/* kncc-consent guard: tracking scripts that other code adds after the page has loaded (tags in
   Google Tag Manager, loaders of other tools) wait for their category, as the ones in the page
   HTML do. window.knccGuard holds the address patterns; consent.js calls knccReleaseHeld(). */
(function (w, d) {
  'use strict';
  var cfg = w.knccGuard;
  if (!cfg || w.knccReleaseHeld || !w.Node || !w.Element) return;

  var held = [];
  // A list such as "analytics marketing" is the weakest need: any one of them is enough.
  var RANK = { 'analytics marketing': 1, preferences: 2, analytics: 3, marketing: 4 };
  var JS_TYPE = /^(?:(?:text|application)\/(?:x-)?(?:javascript|ecmascript)|module)?$/i;

  function consent() {
    var m = d.cookie.match(/(?:^|; )kncc_consent=([^;]*)/);
    if (!m) return {};
    try { return JSON.parse(decodeURIComponent(m[1])) || {}; } catch (e) { return {}; }
  }

  function granted(cats, c) {
    var list = cats.split(' ');
    for (var i = 0; i < list.length; i++) {
      if (c[list[i]]) return true;
    }
    return false;
  }

  function categoryFor(url) {
    var u = String(url).toLowerCase(), i, found = '';
    // The site owner's "Block by address" rules come first, as on the server.
    for (i = 0; i < cfg.rules.length; i++) {
      if (u.indexOf(cfg.rules[i][0]) !== -1) return cfg.rules[i][1] === 'necessary' ? '' : cfg.rules[i][1];
    }
    // Google tag files follow Consent Mode. A container or an Ads tag serves analytics and
    // marketing alike; a tag that can carry Google Analytics (G- or GT- ID) waits for analytics.
    for (i = 0; i < cfg.google.length; i++) {
      if (u.indexOf(cfg.google[i]) !== -1) {
        if (!cfg.holdGoogle) return '';
        return /[?&]id=gt?-/.test(u) ? 'analytics' : 'analytics marketing';
      }
    }
    for (i = 0; i < cfg.patterns.length; i++) {
      if (u.indexOf(cfg.patterns[i][0]) !== -1 && RANK[cfg.patterns[i][1]] > (RANK[found] || 0)) found = cfg.patterns[i][1];
    }
    return found;
  }

  function isJs(type) {
    return type === null || JS_TYPE.test(type.replace(/^\s+|\s+$/g, ''));
  }

  // A script that is not JavaScript yet: what speed plugins (WP Rocket, LiteSpeed, Debloat...)
  // leave in the page and swap for a runnable copy on the first interaction.
  function isDelayed(el) {
    return !!el && el.nodeName === 'SCRIPT' && !isJs(el.getAttribute('type'));
  }

  function hold(s, swap) {
    if (s.hasAttribute('data-kncc-injected') || s.hasAttribute('data-kncc-held') || s.hasAttribute('data-kncc-released')) return;
    var src = s.getAttribute('src');
    var type = s.getAttribute('type');
    if (!src || !isJs(type)) return;
    var cat = categoryFor(s.src || src);
    if (!cat || granted(cat, consent())) return;
    // A script inserted with a type that is not JavaScript is not run, and it is not marked as
    // started either, so the same element can run later. Keeping the element keeps its onload.
    s.setAttribute('data-kncc-held', cat);
    s.setAttribute('type', 'text/plain');
    held.push({ el: s, cat: cat, type: type });
    // Speed plugins run their copies one at a time, each after the previous one has loaded.
    // Report this one as failed, as an ad blocker would, so the scripts after it still run.
    if (swap) {
      w.setTimeout(function () {
        try { if (s.hasAttribute('data-kncc-held')) s.dispatchEvent(new w.Event('error')); } catch (e) { /* old browser */ }
      }, 0);
    }
  }

  function unhold(h) {
    h.el.removeAttribute('data-kncc-held');
    if (h.type === null) h.el.removeAttribute('type'); else h.el.setAttribute('type', h.type);
  }

  function scan(node, swap) {
    if (!node || typeof node !== 'object') return;
    if (node.nodeType === 1) {
      if (node.nodeName === 'SCRIPT') { hold(node, swap); return; }
      if (!node.firstElementChild) return;
    } else if (node.nodeType !== 11 || !node.firstChild) {
      return;
    }
    var list = node.querySelectorAll('script[src]');
    for (var i = 0; i < list.length; i++) hold(list[i], swap);
  }

  function wrap(proto, name, pick) {
    var orig = proto[name];
    if (typeof orig !== 'function') return;
    proto[name] = function () {
      var count = held.length;
      try { pick(arguments, this); } catch (e) { /* never stand in the way of the page's own code */ }
      try {
        return orig.apply(this, arguments);
      } catch (err) {
        // The page's own call failed and inserted nothing, so let go of what it would have added.
        held.splice(count).forEach(unhold);
        throw err;
      }
    };
  }

  // The second argument of insertBefore and replaceChild, or the element that before, after
  // and replaceWith are called on, tells whether a delayed script is being swapped.
  var first = function (a) { scan(a[0], isDelayed(a[1])); };
  var every = function (a, self) { var swap = isDelayed(self); for (var i = 0; i < a.length; i++) scan(a[i], swap); };
  wrap(w.Node.prototype, 'appendChild', first);
  wrap(w.Node.prototype, 'insertBefore', first);
  wrap(w.Node.prototype, 'replaceChild', first);
  wrap(w.Element.prototype, 'insertAdjacentElement', function (a, self) { scan(a[1], isDelayed(self)); });
  ['append', 'prepend', 'before', 'after', 'replaceWith'].forEach(function (name) {
    wrap(w.Element.prototype, name, every);
  });

  // Runs the held scripts the given consent allows, in the order they were added.
  w.knccReleaseHeld = function (c) {
    var keep = [];
    held.forEach(function (h) {
      if (!c || !granted(h.cat, c)) { keep.push(h); return; }
      var s = h.el, parent = s.parentNode, next = s.nextSibling;
      if (parent) parent.removeChild(s);
      unhold(h);
      s.setAttribute('data-kncc-released', '1');
      try {
        (parent || d.head || d.documentElement).insertBefore(s, parent && next && next.parentNode === parent ? next : null);
      } catch (e) { /* the page took its parent away; nothing left to run it in */ }
    });
    held = keep;
  };
})(window, document);
