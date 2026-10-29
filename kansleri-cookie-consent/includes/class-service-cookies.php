<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Service_Cookies {

  /**
   * Providers whose scripts are always trusted without HTML verification.
   */
  private static function get_always_trust_providers() {
    return array(
      'Kansleri Cookie Consent',
      'WP Consent API',
      'WordPress',
      'WooCommerce',
      'Polylang',
      'MailPoet',
      'Cloudflare',
    );
  }

  /**
   * HTML substrings that indicate a tracking provider is active on the site.
   */
  public static function get_provider_signatures() {
    return array(
      // Script paths, not the bare domain: Site Kit prints a googletagmanager.com dns-prefetch
      // on every page even when no Google tag is configured.
      'Google'    => array('googletagmanager.com/gtag', 'googletagmanager.com/gtm.js', 'gtg/measurement.php', 'google-analytics.com'),
      'Meta'      => array('connect.facebook.net', 'facebook.net/tr', 'fbevents.js'),
      'HubSpot'   => array('js.hs-scripts.com', '.hubspot.com', 'hs-analytics'),
      'LinkedIn'  => array('snap.licdn.com', 'platform.linkedin.com'),
      'Hotjar'    => array('static.hotjar.com'),
      'Plausible' => array('plausible.io'),
      'Microsoft' => array('scripts.clarity.ms', 'clarity.ms/tag'),
      'Trustmary' => array('widget.trustmary.com', 'embed.trustmary.com'),
    );
  }

  /**
   * Remove this plugin's own scripts from page HTML before it is searched for providers.
   * The injection guard prints the tracker URL patterns it watches for on every page, so
   * the HTML of a site without a single tracker still contains connect.facebook.net etc.
   * Scripts are recognised by their kncc- id and, in case an optimiser drops the id, by
   * the config they set.
   */
  public static function strip_own_scripts($html) {
    if (!is_string($html) || stripos($html, 'kncc') === false) {
      return $html;
    }

    $stripped = preg_replace_callback(
      '/<script\b([^>]*)>(.*?)<\/script>/is',
      function ($m) {
        $own = preg_match('/\sid\s*=\s*["\']?kncc-/i', $m[1])
          || strpos($m[2], 'window.knccGuard=') !== false
          || strpos($m[2], 'window.knccConfig=') !== false;
        return $own ? '' : $m[0];
      },
      $html
    );

    return is_string($stripped) ? $stripped : $html;
  }

  /**
   * Static cookie names expected per provider when their script is on the site.
   *
   * Durations and descriptions are stored in English and translated when shown, so every
   * one of them must also be listed in KNCC_Scanner::get_cookie_texts().
   */
  public static function get_cookie_templates($provider) {
    $templates = array(
      'Google' => array(
        array(
          'name'        => '_ga',
          'provider'    => 'Google',
          'category'    => 'analytics',
          'duration'    => '2 years',
          'description' => 'Google Analytics: distinguishes unique users.',
        ),
        array(
          'name'        => '_gid',
          'provider'    => 'Google',
          'category'    => 'analytics',
          'duration'    => '24 hours',
          'description' => 'Google Analytics: distinguishes users for 24 hours.',
        ),
      ),
      'Meta' => array(
        array(
          'name'        => '_fbp',
          'provider'    => 'Meta',
          'category'    => 'marketing',
          'duration'    => '90 days',
          'description' => 'Meta Pixel: tracks visits across websites for ad targeting.',
        ),
        array(
          'name'        => '_fbc',
          'provider'    => 'Meta',
          'category'    => 'marketing',
          'duration'    => '90 days',
          'description' => 'Meta Pixel: stores click identifiers.',
        ),
      ),
      'HubSpot' => array(
        array(
          'name'        => 'hubspotutk',
          'provider'    => 'HubSpot',
          'category'    => 'marketing',
          'duration'    => '6 months',
          'description' => 'HubSpot: tracks visitor identity.',
        ),
        array(
          'name'        => '__hstc',
          'provider'    => 'HubSpot',
          'category'    => 'marketing',
          'duration'    => '6 months',
          'description' => 'HubSpot: tracks session information.',
        ),
        array(
          'name'        => '__hssc',
          'provider'    => 'HubSpot',
          'category'    => 'marketing',
          'duration'    => '30 minutes',
          'description' => 'HubSpot: tracks sessions for analytics.',
        ),
        array(
          'name'        => '__hssrc',
          'provider'    => 'HubSpot',
          'category'    => 'analytics',
          'duration'    => 'Session',
          'description' => 'HubSpot: determines if visitor has restarted browser.',
        ),
      ),
      'LinkedIn' => array(
        array(
          'name'        => 'li_sugr',
          'provider'    => 'LinkedIn',
          'category'    => 'marketing',
          'duration'    => '90 days',
          'description' => 'LinkedIn: browser identifier for ad targeting.',
        ),
        array(
          'name'        => 'bcookie',
          'provider'    => 'LinkedIn',
          'category'    => 'marketing',
          'duration'    => '1 year',
          'description' => 'LinkedIn: browser identifier.',
        ),
      ),
      'Hotjar' => array(
        array(
          'name'        => '_hjSessionUser_*',
          'provider'    => 'Hotjar',
          'category'    => 'analytics',
          'duration'    => 'Varies',
          'description' => 'Hotjar: tracks user behaviour and feedback.',
        ),
      ),
      'Plausible' => array(
        array(
          'name'        => 'plausible_ignore',
          'provider'    => 'Plausible',
          'category'    => 'analytics',
          'duration'    => 'Session',
          'description' => 'Plausible: privacy-friendly analytics.',
        ),
      ),
      'Microsoft' => array(
        array(
          'name'        => '_clck',
          'provider'    => 'Microsoft',
          'category'    => 'analytics',
          'duration'    => '1 year',
          'description' => 'Microsoft Clarity: stores a user ID and preferences.',
        ),
        array(
          'name'        => '_clsk',
          'provider'    => 'Microsoft',
          'category'    => 'analytics',
          'duration'    => '1 day',
          'description' => 'Microsoft Clarity: connects page views into a session.',
        ),
      ),
      'Trustmary' => array(
        array(
          'name'        => 'tmary',
          'provider'    => 'Trustmary',
          'category'    => 'analytics',
          'duration'    => 'Persistent',
          'description' => 'Trustmary: stores a visitor ID, the landing page and conversions in the browser’s local storage.',
        ),
      ),
      'Kansleri Cookie Consent' => array(
        array(
          'name'        => 'kncc_consent',
          'provider'    => 'Kansleri Cookie Consent',
          'category'    => 'necessary',
          'duration'    => '1 year',
          'description' => 'Stores your cookie consent preferences.',
        ),
      ),
      'WP Consent API' => array(
        array(
          'name'        => 'wp_consent_functional',
          'provider'    => 'WP Consent API',
          'category'    => 'necessary',
          'duration'    => '30 days',
          'description' => 'WP Consent API: stores your consent choice for necessary cookies. Synced from your consent banner.',
        ),
        array(
          'name'        => 'wp_consent_statistics',
          'provider'    => 'WP Consent API',
          'category'    => 'necessary',
          'duration'    => '30 days',
          'description' => 'WP Consent API: stores your consent choice for analytics cookies. Synced from your consent banner.',
        ),
        array(
          'name'        => 'wp_consent_statistics-anonymous',
          'provider'    => 'WP Consent API',
          'category'    => 'necessary',
          'duration'    => '30 days',
          'description' => 'WP Consent API: stores your consent choice for anonymous statistics. Synced from your consent banner.',
        ),
        array(
          'name'        => 'wp_consent_marketing',
          'provider'    => 'WP Consent API',
          'category'    => 'necessary',
          'duration'    => '30 days',
          'description' => 'WP Consent API: stores your consent choice for marketing cookies. Synced from your consent banner.',
        ),
        array(
          'name'        => 'wp_consent_preferences',
          'provider'    => 'WP Consent API',
          'category'    => 'necessary',
          'duration'    => '30 days',
          'description' => 'WP Consent API: stores your consent choice for preference cookies. Synced from your consent banner.',
        ),
        array(
          'name'        => 'wp_consent_consented_services',
          'provider'    => 'WP Consent API',
          'category'    => 'necessary',
          'duration'    => '30 days',
          'description' => 'WP Consent API: stores per-service consent overrides used by integrated plugins.',
        ),
      ),
    );

    return isset($templates[$provider]) ? $templates[$provider] : array();
  }

  /**
   * Check whether a provider's tracking script appears in the given HTML snapshot.
   */
  public static function provider_runs_on_site($provider, $html = null) {
    if (in_array($provider, self::get_always_trust_providers(), true)) {
      return true;
    }

    $signatures = self::get_provider_signatures();
    if (!isset($signatures[$provider])) {
      return true;
    }

    if ($html === null) {
      $html = kncc_frontend_html_snapshot();
    }

    if ($html === '') {
      return true;
    }

    foreach ($signatures[$provider] as $sig) {
      if (stripos($html, $sig) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Return provider names whose scripts are detected in the homepage HTML.
   */
  public static function detect_services_on_site($html = null) {
    if ($html === null) {
      $html = kncc_frontend_html_snapshot();
    }

    $active = array('Kansleri Cookie Consent');

    if (class_exists('KNCC_WP_Consent_API') && KNCC_WP_Consent_API::is_active()) {
      $active[] = 'WP Consent API';
    }

    if ($html === '') {
      return $active;
    }

    foreach (self::get_provider_signatures() as $provider => $sigs) {
      foreach ($sigs as $sig) {
        if (stripos($html, $sig) !== false) {
          $active[] = $provider;
          break;
        }
      }
    }

    return array_values(array_unique($active));
  }

  /**
   * Parse homepage HTML for provider-specific cookie names (e.g. GA4 measurement ID).
   */
  public static function extract_dynamic_cookies($provider, $html) {
    $cookies = array();

    if ($provider !== 'Google' || $html === '') {
      return $cookies;
    }

    $ids = array();
    if (preg_match_all('/gtag\/js\?id=G-([A-Z0-9]+)/i', $html, $matches)) {
      $ids = array_merge($ids, $matches[1]);
    }
    if (preg_match_all("/gtag\s*\(\s*['\"]config['\"]\s*,\s*['\"]G-([A-Z0-9]+)['\"]/i", $html, $matches)) {
      $ids = array_merge($ids, $matches[1]);
    }

    $ids = array_unique($ids);
    foreach ($ids as $id) {
      $cookies[] = array(
        'name'        => '_ga_' . $id,
        'provider'    => 'Google',
        'category'    => 'analytics',
        'duration'    => '2 years',
        'description' => 'Google Analytics: stores and counts page views.',
      );
    }

    return $cookies;
  }

  /**
   * Check whether a cookie name is already covered by the registry.
   */
  private static function is_cookie_registered($name, $existing) {
    foreach ($existing as $cookie) {
      $registered = isset($cookie['name']) ? $cookie['name'] : '';
      if ($registered === $name) {
        return true;
      }

      // Wildcard template entry (e.g. _hjSessionUser_*).
      if (strpos($registered, '*') !== false) {
        $pattern = '/^' . str_replace('\*', '.*', preg_quote($registered, '/')) . '$/';
        if (preg_match($pattern, $name)) {
          return true;
        }
      }

      // Existing _ga_* entry covers any GA4 property cookie.
      if (preg_match('/^_ga_.+\*?$/', $registered) && preg_match('/^_ga_/', $name)) {
        return true;
      }
    }

    // Also check against KNCC_Scanner pattern matches.
    $meta = KNCC_Scanner::identify_cookie($name);
    if ($meta) {
      foreach ($existing as $cookie) {
        $registered = isset($cookie['name']) ? $cookie['name'] : '';
        if ($registered === $name) {
          return true;
        }
        $registered_meta = KNCC_Scanner::identify_cookie($registered);
        if ($registered_meta
          && $registered_meta['provider'] === $meta['provider']
          && preg_match('/^_ga_/', $name)
          && preg_match('/^_ga_/', $registered)
        ) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Return cookie rows that should be added based on detected site services.
   */
  public static function infer_cookies($html = null) {
    if ($html === null) {
      $html = kncc_frontend_html_snapshot();
    }

    $existing = kncc_get_cookies();
    $inferred = array();
    $providers = self::detect_services_on_site($html);

    foreach ($providers as $provider) {
      $templates = self::get_cookie_templates($provider);
      $dynamic = self::extract_dynamic_cookies($provider, $html);
      $candidates = array_merge($templates, $dynamic);

      foreach ($candidates as $cookie) {
        $name = $cookie['name'];
        if (strpos($name, '*') !== false) {
          continue;
        }
        if (self::is_cookie_registered($name, $existing) || self::is_cookie_registered($name, $inferred)) {
          continue;
        }
        $inferred[] = $cookie;
      }
    }

    return $inferred;
  }

  /**
   * Merge inferred cookies into kncc_cookies and persist.
   *
   * @return array{added: array, skipped: int}
   */
  public static function sync_inferred_cookies($html = null) {
    KNCC_Scanner::backfill_known_cookie_meta();

    if ($html === null) {
      $html = kncc_frontend_html_snapshot();
    }

    if ($html !== '' && get_option('kncc_guard_cleanup') === 'pending') {
      self::clean_up_guard_false_positives($html);
      update_option('kncc_guard_cleanup', 'done', false);
    }

    $inferred = self::infer_cookies($html);
    if (empty($inferred)) {
      return array(
        'added'   => array(),
        'skipped' => 0,
      );
    }

    $existing = kncc_get_cookies();
    $added = array();

    foreach ($inferred as $cookie) {
      if (self::is_cookie_registered($cookie['name'], $existing)) {
        continue;
      }
      $existing[] = $cookie;
      $added[] = $cookie;
    }

    if (!empty($added)) {
      update_option('kncc_cookies', $existing, false);
    }

    return array(
      'added'   => $added,
      'skipped' => count($inferred) - count($added),
    );
  }

  /**
   * Undo the cookies that 1.8.10 added for services the site does not use. That version
   * searched HTML that still held the injection guard's tracker patterns, so the Cookies
   * tab and the daily check took every service in them for installed.
   *
   * Rows the scanner or the site owner added look the same, so only what 1.8.10 itself left
   * is touched: it appended the rows in one go, in template order. A row is removed when it
   * sits in such a block of adjacent rows covering two or more of these services and is still
   * exactly as the template added it. The plugin page lists the removed rows and can put them
   * back, for a site that loads one of these services in a way the homepage HTML does not show.
   * With Google Tag Manager on the page any of them can come from the container, so the rows
   * are only listed there.
   *
   * @param string $html Homepage HTML snapshot without the plugin's own scripts.
   */
  private static function clean_up_guard_false_positives($html) {
    $faked = array_diff(
      self::detect_services_on_site(wp_json_encode(KNCC_Script_Blocker::get_injection_guard_config())),
      self::detect_services_on_site($html)
    );

    // Template names of those services, numbered in the order 1.8.10 added them.
    $order = array();
    $provider_of = array();
    foreach ($faked as $provider) {
      foreach (self::get_cookie_templates($provider) as $template) {
        if (strpos($template['name'], '*') === false) {
          $order[$template['name']] = count($order);
          $provider_of[$template['name']] = $provider;
        }
      }
    }
    if (empty($order)) {
      return;
    }

    // 1.8.10 added every one of these services. If one is missing, that never happened here.
    $cookies = kncc_get_cookies();
    if (!is_array($cookies)) {
      return;
    }
    $names = wp_list_pluck($cookies, 'name');
    foreach (array_unique($provider_of) as $provider) {
      if (!array_intersect(array_keys($provider_of, $provider, true), $names)) {
        return;
      }
    }

    // Split the list into blocks of adjacent rows that follow that order.
    $blocks = array();
    $block = array();
    $last = -1;
    foreach ($cookies as $i => $cookie) {
      $name = isset($cookie['name']) ? $cookie['name'] : '';
      $position = isset($order[$name]) ? $order[$name] : -1;
      if ($position <= $last) {
        $blocks[] = $block;
        $block = array();
      }
      if ($position >= 0) {
        $block[] = $i;
      }
      $last = $position;
    }
    $blocks[] = $block;

    $listed = array();
    foreach ($blocks as $block) {
      $providers = array();
      foreach ($block as $i) {
        $providers[$provider_of[$cookies[$i]['name']]] = true;
      }
      if (count($providers) < 2) {
        continue;
      }
      foreach ($block as $i) {
        if (self::is_unedited_template($cookies[$i], $faked)) {
          $listed[$i] = $cookies[$i]['name'];
        }
      }
    }
    if (empty($listed)) {
      return;
    }

    if (stripos($html, 'googletagmanager.com/gtm.js') !== false) {
      update_option('kncc_guard_kept_cookies', array_values($listed), false);
      return;
    }

    update_option('kncc_cookies', array_values(array_diff_key($cookies, $listed)), false);
    // Whole rows, so the plugin page can put them back.
    update_option('kncc_guard_removed_cookies', array_values(array_intersect_key($cookies, $listed)), false);
  }

  /**
   * Put the cookies that the 1.8.10 cleanup removed back in the list, for a site that does use
   * those services (one that loads them from a script the homepage HTML does not show).
   */
  public static function restore_guard_removed_cookies() {
    $removed = get_option('kncc_guard_removed_cookies');
    $cookies = kncc_get_cookies();
    if (!is_array($removed) || !is_array($cookies)) {
      return;
    }
    foreach ($removed as $cookie) {
      if (is_array($cookie) && !empty($cookie['name']) && !self::is_cookie_registered($cookie['name'], $cookies)) {
        $cookies[] = $cookie;
      }
    }
    update_option('kncc_cookies', $cookies, false);
  }

  /**
   * Whether a saved cookie row is a template of one of the given providers, left unedited.
   */
  private static function is_unedited_template($cookie, $providers) {
    $provider = isset($cookie['provider']) ? $cookie['provider'] : '';
    if (!in_array($provider, $providers, true)) {
      return false;
    }

    foreach (self::get_cookie_templates($provider) as $template) {
      $same = true;
      foreach ($template as $key => $value) {
        if (!isset($cookie[$key]) || $cookie[$key] !== $value) {
          $same = false;
          break;
        }
      }
      if ($same) {
        return true;
      }
    }

    return false;
  }

  /**
   * Find registered cookies whose provider scripts are no longer on the site.
   *
   * @param string|null $html Homepage HTML snapshot.
   * @return array<int, array{name: string, provider: string, category: string, duration: string, description: string, reason: string}>
   */
  public static function find_stale_cookies($html = null) {
    if ($html === null) {
      $html = kncc_frontend_html_snapshot();
    }

    if ($html === '') {
      return array();
    }

    $active_providers = self::detect_services_on_site($html);
    $always_trust = self::get_always_trust_providers();
    $known_providers = array_keys(self::get_provider_signatures());
    $stale = array();
    $seen = array();

    foreach (kncc_get_cookies() as $cookie) {
      $name = isset($cookie['name']) ? trim($cookie['name']) : '';
      if ($name === '' || isset($seen[$name])) {
        continue;
      }
      $seen[$name] = true;

      $category = isset($cookie['category']) ? $cookie['category'] : '';
      if ($category === 'necessary') {
        continue;
      }

      $provider = isset($cookie['provider']) ? trim($cookie['provider']) : '';
      $meta = null;
      if ($provider === '') {
        $meta = KNCC_Scanner::identify_cookie($name);
        if (!$meta || empty($meta['provider'])) {
          continue;
        }
        $provider = $meta['provider'];
      }

      if (in_array($provider, $always_trust, true)) {
        continue;
      }

      if (!in_array($provider, $known_providers, true)) {
        continue;
      }

      if (in_array($provider, $active_providers, true)) {
        continue;
      }

      $stale[] = array(
        'name'        => $name,
        'provider'    => $provider,
        'category'    => $category !== '' ? $category : (isset($meta['category']) ? $meta['category'] : ''),
        'duration'    => isset($cookie['duration']) ? $cookie['duration'] : '',
        'description' => isset($cookie['description']) ? $cookie['description'] : '',
        'reason'      => sprintf(
          /* translators: %s: tracking provider name, e.g. Google */
          __('%s scripts no longer detected on homepage', 'kansleri-cookie-consent'),
          $provider
        ),
      );
    }

    return $stale;
  }
}
