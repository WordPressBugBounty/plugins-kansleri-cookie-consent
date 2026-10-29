<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Scanner {

  public function __construct() {
    add_action('wp_ajax_kncc_process_scan', array($this, 'process_scan'));
    add_action('kncc_daily_cookie_check', array($this, 'cron_check_cookies'));
  }

  /**
   * Known cookie patterns: regex => meta.
   * Order matters — first match wins.
   *
   * Durations and descriptions are stored in English and translated when shown, so every
   * one of them must also be listed in get_cookie_texts().
   */
  public static function get_known_cookies() {
    return array(
      // Google Analytics
      array('pattern' => '/^_ga_/', 'provider' => 'Google', 'category' => 'analytics', 'duration' => '2 years', 'description' => 'Google Analytics: stores and counts page views.'),
      array('pattern' => '/^_ga$/', 'provider' => 'Google', 'category' => 'analytics', 'duration' => '2 years', 'description' => 'Google Analytics: distinguishes unique users.'),
      array('pattern' => '/^_gid$/', 'provider' => 'Google', 'category' => 'analytics', 'duration' => '24 hours', 'description' => 'Google Analytics: distinguishes users for 24 hours.'),
      array('pattern' => '/^_gat/', 'provider' => 'Google', 'category' => 'analytics', 'duration' => '1 minute', 'description' => 'Google Analytics: throttles request rate.'),
      array('pattern' => '/^_gcl_au$/', 'provider' => 'Google', 'category' => 'marketing', 'duration' => '90 days', 'description' => 'Google Ads: stores conversion data.'),
      array('pattern' => '/^_gcl_/', 'provider' => 'Google', 'category' => 'marketing', 'duration' => '90 days', 'description' => 'Google Ads: click tracking.'),

      // Google Tag Manager
      array('pattern' => '/^_dc_gtm_/', 'provider' => 'Google', 'category' => 'analytics', 'duration' => '1 minute', 'description' => 'Google Tag Manager: throttles request rate.'),

      // Facebook / Meta
      array('pattern' => '/^_fbp$/', 'provider' => 'Meta', 'category' => 'marketing', 'duration' => '90 days', 'description' => 'Meta Pixel: tracks visits across websites for ad targeting.'),
      array('pattern' => '/^_fbc$/', 'provider' => 'Meta', 'category' => 'marketing', 'duration' => '90 days', 'description' => 'Meta Pixel: stores click identifiers.'),
      array('pattern' => '/^fr$/', 'provider' => 'Meta', 'category' => 'marketing', 'duration' => '90 days', 'description' => 'Facebook: ad delivery and measurement.'),

      // HubSpot (generic __hs row last, or it would shadow the specific ones)
      array('pattern' => '/^hubspotutk$/', 'provider' => 'HubSpot', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'HubSpot: tracks visitor identity.'),
      array('pattern' => '/^__hstc$/', 'provider' => 'HubSpot', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'HubSpot: tracks session information.'),
      array('pattern' => '/^__hssc$/', 'provider' => 'HubSpot', 'category' => 'marketing', 'duration' => '30 minutes', 'description' => 'HubSpot: tracks sessions for analytics.'),
      array('pattern' => '/^__hssrc$/', 'provider' => 'HubSpot', 'category' => 'analytics', 'duration' => 'Session', 'description' => 'HubSpot: determines if visitor has restarted browser.'),
      array('pattern' => '/^__hs/', 'provider' => 'HubSpot', 'category' => 'marketing', 'duration' => 'Varies', 'description' => 'HubSpot: analytics and tracking.'),

      // LinkedIn
      array('pattern' => '/^li_sugr$/', 'provider' => 'LinkedIn', 'category' => 'marketing', 'duration' => '90 days', 'description' => 'LinkedIn: browser identifier for ad targeting.'),
      array('pattern' => '/^bcookie$/', 'provider' => 'LinkedIn', 'category' => 'marketing', 'duration' => '1 year', 'description' => 'LinkedIn: browser identifier.'),
      array('pattern' => '/^lidc$/', 'provider' => 'LinkedIn', 'category' => 'marketing', 'duration' => '24 hours', 'description' => 'LinkedIn: data centre selection.'),

      // WordPress core
      array('pattern' => '/^wp-settings-time-/', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => '1 year', 'description' => 'WordPress: stores admin settings timestamp.'),
      array('pattern' => '/^wp-settings-/', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => '1 year', 'description' => 'WordPress: stores admin personalisation settings.'),
      array('pattern' => '/^wordpress_logged_in/', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => 'Session', 'description' => 'WordPress: authentication cookie for logged-in users.'),
      array('pattern' => '/^wordpress_sec/', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => 'Session', 'description' => 'WordPress: secure authentication cookie.'),
      array('pattern' => '/^wordpress_test_cookie$/', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => 'Session', 'description' => 'WordPress: tests if cookies are enabled.'),
      array('pattern' => '/^wp_lang$/', 'provider' => 'WordPress', 'category' => 'necessary', 'duration' => 'Session', 'description' => 'WordPress: stores language preference.'),
      array('pattern' => '/^comment_author/', 'provider' => 'WordPress', 'category' => 'preferences', 'duration' => '1 year', 'description' => 'WordPress: remembers comment author details.'),

      // WooCommerce
      array('pattern' => '/^(wp_)?woocommerce_/', 'provider' => 'WooCommerce', 'category' => 'necessary', 'duration' => 'Session', 'description' => 'WooCommerce: stores cart and session data.'),
      array('pattern' => '/^wc_/', 'provider' => 'WooCommerce', 'category' => 'necessary', 'duration' => 'Session', 'description' => 'WooCommerce: stores customer session.'),

      // Sourcebuster (WooCommerce Order Attribution) - marketing/attribution
      array('pattern' => '/^sbjs_migrations$/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'Order attribution: keeps traffic-source tracking data compatible across updates.'),
      array('pattern' => '/^sbjs_current_add$/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'Order attribution: records how you reached the site on your current visit.'),
      array('pattern' => '/^sbjs_first_add$/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'Order attribution: records how you first reached the site.'),
      array('pattern' => '/^sbjs_current$/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'Order attribution: stores the traffic source and campaign of your current visit.'),
      array('pattern' => '/^sbjs_first$/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'Order attribution: stores the traffic source and campaign of your first visit.'),
      array('pattern' => '/^sbjs_udata$/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'Order attribution: stores visitor/session data used for marketing attribution.'),
      array('pattern' => '/^sbjs_session$/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '30 minutes', 'description' => 'Order attribution: links page views within a visit for attribution.'),
      array('pattern' => '/^sbjs_/', 'provider' => 'WooCommerce Order Attribution (Sourcebuster)', 'category' => 'marketing', 'duration' => '6 months', 'description' => 'Order attribution: tracks the marketing source of your visit.'),

      // Jetpack (WordPress.com stats) - analytics
      array('pattern' => '/^tk_ai$/', 'provider' => 'Jetpack', 'category' => 'analytics', 'duration' => 'Session', 'description' => 'Jetpack Stats: assigns an anonymous identifier used to measure site traffic.'),
      array('pattern' => '/^tk_qs$/', 'provider' => 'Jetpack', 'category' => 'analytics', 'duration' => '30 minutes', 'description' => 'Jetpack Stats: records referral and navigation data for analytics.'),
      array('pattern' => '/^tk_/', 'provider' => 'Jetpack', 'category' => 'analytics', 'duration' => 'Session', 'description' => 'Jetpack Stats: measures site traffic and visitor activity.'),

      // Polylang
      array('pattern' => '/^pll_language$/', 'provider' => 'Polylang', 'category' => 'necessary', 'duration' => '1 year', 'description' => 'Polylang: stores visitor language preference.'),

      // MailPoet
      array('pattern' => '/^mailpoet_/', 'provider' => 'MailPoet', 'category' => 'analytics', 'duration' => 'Varies', 'description' => 'MailPoet: tracks email subscriber activity.'),

      // Plausible
      array('pattern' => '/^plausible_/', 'provider' => 'Plausible', 'category' => 'analytics', 'duration' => 'Varies', 'description' => 'Plausible: privacy-friendly analytics.'),

      // Cloudflare
      array('pattern' => '/^__cf_bm$/', 'provider' => 'Cloudflare', 'category' => 'necessary', 'duration' => '30 minutes', 'description' => 'Cloudflare: bot management cookie.'),
      array('pattern' => '/^cf_clearance$/', 'provider' => 'Cloudflare', 'category' => 'necessary', 'duration' => '30 minutes', 'description' => 'Cloudflare: verifies legitimate visitor.'),
      array('pattern' => '/^__cfduid$/', 'provider' => 'Cloudflare', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'Cloudflare: identifies trusted web traffic.'),

      // Hotjar
      array('pattern' => '/^_hj/', 'provider' => 'Hotjar', 'category' => 'analytics', 'duration' => 'Varies', 'description' => 'Hotjar: tracks user behaviour and feedback.'),

      // Microsoft Clarity
      array('pattern' => '/^_clck$/', 'provider' => 'Microsoft', 'category' => 'analytics', 'duration' => '1 year', 'description' => 'Microsoft Clarity: stores a user ID and preferences.'),
      array('pattern' => '/^_clsk$/', 'provider' => 'Microsoft', 'category' => 'analytics', 'duration' => '1 day', 'description' => 'Microsoft Clarity: connects page views into a session.'),

      // Trustmary (a localStorage item, not a cookie; listed so the policy shows it and a
      // rejected analytics choice removes it)
      array('pattern' => '/^tmary$/', 'provider' => 'Trustmary', 'category' => 'analytics', 'duration' => 'Persistent', 'description' => 'Trustmary: stores a visitor ID, the landing page and conversions in the browser’s local storage.'),

      // Advertising, analytics and chat services the script blocker holds back until consent.
      array('pattern' => '/^_rdt_uuid$/', 'provider' => 'Reddit', 'category' => 'marketing', 'duration' => '90 days', 'description' => 'Reddit Pixel: identifies the browser for ad measurement.'),
      array('pattern' => '/^_uetsid$/', 'provider' => 'Microsoft Advertising', 'category' => 'marketing', 'duration' => '1 day', 'description' => 'Microsoft Advertising: groups page views into a session for ad measurement.'),
      array('pattern' => '/^_uetvid$/', 'provider' => 'Microsoft Advertising', 'category' => 'marketing', 'duration' => '1 year', 'description' => 'Microsoft Advertising: identifies the visitor across visits for ad measurement.'),
      array('pattern' => '/^cto_bundle$/', 'provider' => 'Criteo', 'category' => 'marketing', 'duration' => '1 year', 'description' => 'Criteo: stores a browser identifier for ad personalisation.'),
      array('pattern' => '/^t_gid$/', 'provider' => 'Taboola', 'category' => 'marketing', 'duration' => '1 year', 'description' => 'Taboola: identifies the browser for ad targeting.'),
      array('pattern' => '/^__kla_id$/', 'provider' => 'Klaviyo', 'category' => 'marketing', 'duration' => '2 years', 'description' => 'Klaviyo: recognises returning visitors for email marketing.'),
      array('pattern' => '/^_ym_/', 'provider' => 'Yandex', 'category' => 'analytics', 'duration' => '1 year', 'description' => 'Yandex Metrica: identifies visitors and stores session settings.'),
      array('pattern' => '/^ajs_/', 'provider' => 'Segment', 'category' => 'analytics', 'duration' => '1 year', 'description' => 'Segment: stores an anonymous visitor ID and traits.'),
      array('pattern' => '/^intercom-/', 'provider' => 'Intercom', 'category' => 'preferences', 'duration' => 'Varies', 'description' => 'Intercom: keeps the chat messenger session and visitor identity.'),
      array('pattern' => '/^crisp-client/', 'provider' => 'Crisp', 'category' => 'preferences', 'duration' => '6 months', 'description' => 'Crisp: keeps the chat session and visitor identity.'),

      // Consent cookies — all strictly necessary; they only store the visitor's
      // consent choice, they do not perform tracking themselves.
      array('pattern' => '/^kncc_consent$/', 'provider' => 'Kansleri Cookie Consent', 'category' => 'necessary', 'duration' => '1 year', 'description' => 'Stores your cookie consent preferences.'),
      array('pattern' => '/^wp_consent_functional$/', 'provider' => 'WP Consent API', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'WP Consent API: stores your consent choice for necessary cookies. Synced from your consent banner.'),
      array('pattern' => '/^wp_consent_statistics$/', 'provider' => 'WP Consent API', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'WP Consent API: stores your consent choice for analytics cookies. Synced from your consent banner.'),
      array('pattern' => '/^wp_consent_statistics-anonymous$/', 'provider' => 'WP Consent API', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'WP Consent API: stores your consent choice for anonymous statistics. Synced from your consent banner.'),
      array('pattern' => '/^wp_consent_marketing$/', 'provider' => 'WP Consent API', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'WP Consent API: stores your consent choice for marketing cookies. Synced from your consent banner.'),
      array('pattern' => '/^wp_consent_preferences$/', 'provider' => 'WP Consent API', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'WP Consent API: stores your consent choice for preference cookies. Synced from your consent banner.'),
      array('pattern' => '/^wp_consent_consented_services$/', 'provider' => 'WP Consent API', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'WP Consent API: stores per-service consent overrides used by integrated plugins.'),
      array('pattern' => '/^wp_consent_/', 'provider' => 'WP Consent API', 'category' => 'necessary', 'duration' => '30 days', 'description' => 'WP Consent API: stores your cookie consent choice. Synced from your consent banner.'),
    );
  }

  /**
   * Built-in durations and descriptions (from get_known_cookies() and the service cookie
   * templates), as English source => translation in the current locale.
   *
   * @see kncc_translate_cookie_texts()
   */
  public static function get_cookie_texts() {
    return array(
      // Durations
      '1 minute'   => _x('1 minute', 'cookie duration', 'kansleri-cookie-consent'),
      '30 minutes' => _x('30 minutes', 'cookie duration', 'kansleri-cookie-consent'),
      '24 hours'   => _x('24 hours', 'cookie duration', 'kansleri-cookie-consent'),
      '1 day'      => _x('1 day', 'cookie duration', 'kansleri-cookie-consent'),
      '30 days'    => _x('30 days', 'cookie duration', 'kansleri-cookie-consent'),
      '90 days'    => _x('90 days', 'cookie duration', 'kansleri-cookie-consent'),
      '6 months'   => _x('6 months', 'cookie duration', 'kansleri-cookie-consent'),
      '1 year'     => _x('1 year', 'cookie duration', 'kansleri-cookie-consent'),
      '2 years'    => _x('2 years', 'cookie duration', 'kansleri-cookie-consent'),
      'Session'    => _x('Session', 'cookie duration', 'kansleri-cookie-consent'),
      'Varies'     => _x('Varies', 'cookie duration', 'kansleri-cookie-consent'),
      'Persistent' => _x('Persistent', 'cookie duration', 'kansleri-cookie-consent'),

      // Google
      'Google Analytics: stores and counts page views.' => __('Google Analytics: stores and counts page views.', 'kansleri-cookie-consent'),
      'Google Analytics: distinguishes unique users.' => __('Google Analytics: distinguishes unique users.', 'kansleri-cookie-consent'),
      'Google Analytics: distinguishes users for 24 hours.' => __('Google Analytics: distinguishes users for 24 hours.', 'kansleri-cookie-consent'),
      'Google Analytics: throttles request rate.' => __('Google Analytics: throttles request rate.', 'kansleri-cookie-consent'),
      'Google Ads: stores conversion data.' => __('Google Ads: stores conversion data.', 'kansleri-cookie-consent'),
      'Google Ads: click tracking.' => __('Google Ads: click tracking.', 'kansleri-cookie-consent'),
      'Google Tag Manager: throttles request rate.' => __('Google Tag Manager: throttles request rate.', 'kansleri-cookie-consent'),

      // Facebook / Meta
      'Meta Pixel: tracks visits across websites for ad targeting.' => __('Meta Pixel: tracks visits across websites for ad targeting.', 'kansleri-cookie-consent'),
      'Meta Pixel: stores click identifiers.' => __('Meta Pixel: stores click identifiers.', 'kansleri-cookie-consent'),
      'Facebook: ad delivery and measurement.' => __('Facebook: ad delivery and measurement.', 'kansleri-cookie-consent'),

      // HubSpot
      'HubSpot: tracks visitor identity.' => __('HubSpot: tracks visitor identity.', 'kansleri-cookie-consent'),
      'HubSpot: tracks session information.' => __('HubSpot: tracks session information.', 'kansleri-cookie-consent'),
      'HubSpot: tracks sessions for analytics.' => __('HubSpot: tracks sessions for analytics.', 'kansleri-cookie-consent'),
      'HubSpot: determines if visitor has restarted browser.' => __('HubSpot: determines if visitor has restarted browser.', 'kansleri-cookie-consent'),
      'HubSpot: analytics and tracking.' => __('HubSpot: analytics and tracking.', 'kansleri-cookie-consent'),

      // LinkedIn
      'LinkedIn: browser identifier for ad targeting.' => __('LinkedIn: browser identifier for ad targeting.', 'kansleri-cookie-consent'),
      'LinkedIn: browser identifier.' => __('LinkedIn: browser identifier.', 'kansleri-cookie-consent'),
      'LinkedIn: data centre selection.' => __('LinkedIn: data centre selection.', 'kansleri-cookie-consent'),

      // WordPress core
      'WordPress: stores admin settings timestamp.' => __('WordPress: stores admin settings timestamp.', 'kansleri-cookie-consent'),
      'WordPress: stores admin personalisation settings.' => __('WordPress: stores admin personalisation settings.', 'kansleri-cookie-consent'),
      'WordPress: authentication cookie for logged-in users.' => __('WordPress: authentication cookie for logged-in users.', 'kansleri-cookie-consent'),
      'WordPress: secure authentication cookie.' => __('WordPress: secure authentication cookie.', 'kansleri-cookie-consent'),
      'WordPress: tests if cookies are enabled.' => __('WordPress: tests if cookies are enabled.', 'kansleri-cookie-consent'),
      'WordPress: stores language preference.' => __('WordPress: stores language preference.', 'kansleri-cookie-consent'),
      'WordPress: remembers comment author details.' => __('WordPress: remembers comment author details.', 'kansleri-cookie-consent'),

      // WooCommerce
      'WooCommerce: stores cart and session data.' => __('WooCommerce: stores cart and session data.', 'kansleri-cookie-consent'),
      'WooCommerce: stores customer session.' => __('WooCommerce: stores customer session.', 'kansleri-cookie-consent'),

      // Sourcebuster (WooCommerce Order Attribution)
      'Order attribution: keeps traffic-source tracking data compatible across updates.' => __('Order attribution: keeps traffic-source tracking data compatible across updates.', 'kansleri-cookie-consent'),
      'Order attribution: records how you reached the site on your current visit.' => __('Order attribution: records how you reached the site on your current visit.', 'kansleri-cookie-consent'),
      'Order attribution: records how you first reached the site.' => __('Order attribution: records how you first reached the site.', 'kansleri-cookie-consent'),
      'Order attribution: stores the traffic source and campaign of your current visit.' => __('Order attribution: stores the traffic source and campaign of your current visit.', 'kansleri-cookie-consent'),
      'Order attribution: stores the traffic source and campaign of your first visit.' => __('Order attribution: stores the traffic source and campaign of your first visit.', 'kansleri-cookie-consent'),
      'Order attribution: stores visitor/session data used for marketing attribution.' => __('Order attribution: stores visitor/session data used for marketing attribution.', 'kansleri-cookie-consent'),
      'Order attribution: links page views within a visit for attribution.' => __('Order attribution: links page views within a visit for attribution.', 'kansleri-cookie-consent'),
      'Order attribution: tracks the marketing source of your visit.' => __('Order attribution: tracks the marketing source of your visit.', 'kansleri-cookie-consent'),

      // Jetpack
      'Jetpack Stats: assigns an anonymous identifier used to measure site traffic.' => __('Jetpack Stats: assigns an anonymous identifier used to measure site traffic.', 'kansleri-cookie-consent'),
      'Jetpack Stats: records referral and navigation data for analytics.' => __('Jetpack Stats: records referral and navigation data for analytics.', 'kansleri-cookie-consent'),
      'Jetpack Stats: measures site traffic and visitor activity.' => __('Jetpack Stats: measures site traffic and visitor activity.', 'kansleri-cookie-consent'),

      // Polylang, MailPoet, Plausible
      'Polylang: stores visitor language preference.' => __('Polylang: stores visitor language preference.', 'kansleri-cookie-consent'),
      'MailPoet: tracks email subscriber activity.' => __('MailPoet: tracks email subscriber activity.', 'kansleri-cookie-consent'),
      'Plausible: privacy-friendly analytics.' => __('Plausible: privacy-friendly analytics.', 'kansleri-cookie-consent'),

      // Cloudflare
      'Cloudflare: bot management cookie.' => __('Cloudflare: bot management cookie.', 'kansleri-cookie-consent'),
      'Cloudflare: verifies legitimate visitor.' => __('Cloudflare: verifies legitimate visitor.', 'kansleri-cookie-consent'),
      'Cloudflare: identifies trusted web traffic.' => __('Cloudflare: identifies trusted web traffic.', 'kansleri-cookie-consent'),

      // Hotjar, Microsoft Clarity
      'Hotjar: tracks user behaviour and feedback.' => __('Hotjar: tracks user behaviour and feedback.', 'kansleri-cookie-consent'),
      'Microsoft Clarity: stores a user ID and preferences.' => __('Microsoft Clarity: stores a user ID and preferences.', 'kansleri-cookie-consent'),
      'Microsoft Clarity: connects page views into a session.' => __('Microsoft Clarity: connects page views into a session.', 'kansleri-cookie-consent'),

      // Trustmary
      'Trustmary: stores a visitor ID, the landing page and conversions in the browser’s local storage.' => __('Trustmary: stores a visitor ID, the landing page and conversions in the browser’s local storage.', 'kansleri-cookie-consent'),

      // Advertising, analytics and chat services
      'Reddit Pixel: identifies the browser for ad measurement.' => __('Reddit Pixel: identifies the browser for ad measurement.', 'kansleri-cookie-consent'),
      'Microsoft Advertising: groups page views into a session for ad measurement.' => __('Microsoft Advertising: groups page views into a session for ad measurement.', 'kansleri-cookie-consent'),
      'Microsoft Advertising: identifies the visitor across visits for ad measurement.' => __('Microsoft Advertising: identifies the visitor across visits for ad measurement.', 'kansleri-cookie-consent'),
      'Criteo: stores a browser identifier for ad personalisation.' => __('Criteo: stores a browser identifier for ad personalisation.', 'kansleri-cookie-consent'),
      'Taboola: identifies the browser for ad targeting.' => __('Taboola: identifies the browser for ad targeting.', 'kansleri-cookie-consent'),
      'Klaviyo: recognises returning visitors for email marketing.' => __('Klaviyo: recognises returning visitors for email marketing.', 'kansleri-cookie-consent'),
      'Yandex Metrica: identifies visitors and stores session settings.' => __('Yandex Metrica: identifies visitors and stores session settings.', 'kansleri-cookie-consent'),
      'Segment: stores an anonymous visitor ID and traits.' => __('Segment: stores an anonymous visitor ID and traits.', 'kansleri-cookie-consent'),
      'Intercom: keeps the chat messenger session and visitor identity.' => __('Intercom: keeps the chat messenger session and visitor identity.', 'kansleri-cookie-consent'),
      'Crisp: keeps the chat session and visitor identity.' => __('Crisp: keeps the chat session and visitor identity.', 'kansleri-cookie-consent'),

      // Consent cookies
      'Stores your cookie consent preferences.' => __('Stores your cookie consent preferences.', 'kansleri-cookie-consent'),
      'WP Consent API: stores your consent choice for necessary cookies. Synced from your consent banner.' => __('WP Consent API: stores your consent choice for necessary cookies. Synced from your consent banner.', 'kansleri-cookie-consent'),
      'WP Consent API: stores your consent choice for analytics cookies. Synced from your consent banner.' => __('WP Consent API: stores your consent choice for analytics cookies. Synced from your consent banner.', 'kansleri-cookie-consent'),
      'WP Consent API: stores your consent choice for anonymous statistics. Synced from your consent banner.' => __('WP Consent API: stores your consent choice for anonymous statistics. Synced from your consent banner.', 'kansleri-cookie-consent'),
      'WP Consent API: stores your consent choice for marketing cookies. Synced from your consent banner.' => __('WP Consent API: stores your consent choice for marketing cookies. Synced from your consent banner.', 'kansleri-cookie-consent'),
      'WP Consent API: stores your consent choice for preference cookies. Synced from your consent banner.' => __('WP Consent API: stores your consent choice for preference cookies. Synced from your consent banner.', 'kansleri-cookie-consent'),
      'WP Consent API: stores per-service consent overrides used by integrated plugins.' => __('WP Consent API: stores per-service consent overrides used by integrated plugins.', 'kansleri-cookie-consent'),
      'WP Consent API: stores your cookie consent choice. Synced from your consent banner.' => __('WP Consent API: stores your cookie consent choice. Synced from your consent banner.', 'kansleri-cookie-consent'),
    );
  }

  public static function identify_cookie($name) {
    foreach (self::get_known_cookies() as $entry) {
      if (preg_match($entry['pattern'], $name)) {
        return array(
          'provider'    => $entry['provider'],
          'category'    => $entry['category'],
          'duration'    => $entry['duration'],
          'description' => $entry['description'],
        );
      }
    }
    return null;
  }

  /**
   * Fill in provider, category, duration, and description for saved cookies
   * that match a known pattern but were previously added as unknown.
   *
   * Two cases are handled:
   * 1. Cookies with no description yet: filled entirely from the known pattern.
   * 2. Cookies that defaulted to the "necessary" category (how unknown cookies
   *    are stored) but actually match a tracking pattern belonging to another
   *    category: the category is corrected even if a description already exists
   *    (e.g. one written by an AI helper). The existing description is kept.
   *
   * @return array Names of cookies that were updated.
   */
  public static function backfill_known_cookie_meta() {
    $cookies = kncc_get_cookies();
    $updated_names = array();

    foreach ($cookies as &$cookie) {
      $name = isset($cookie['name']) ? $cookie['name'] : '';
      if ($name === '') {
        continue;
      }

      $meta = self::identify_cookie($name);
      if (!$meta) {
        continue;
      }

      $description_empty = empty($cookie['description']);
      $current_category  = isset($cookie['category']) ? $cookie['category'] : '';
      // Unknown cookies are stored as "necessary"; correct them when the known
      // pattern says they belong to a category that requires consent.
      $miscategorized = ($current_category === 'necessary' && $meta['category'] !== 'necessary');
      // Consent-record cookies (wp_consent_*, kncc_consent) only store choices;
      // correct them when previously saved under a tracking category.
      $is_consent_record = (bool) preg_match('/^(kncc_consent|wp_consent_)/', $name);
      if ($is_consent_record && $meta['category'] === 'necessary' && $current_category !== 'necessary') {
        $miscategorized = true;
      }

      if (!$description_empty && !$miscategorized) {
        continue;
      }

      if ($description_empty) {
        $cookie['provider']    = $meta['provider'];
        $cookie['category']    = $meta['category'];
        $cookie['duration']    = $meta['duration'];
        $cookie['description'] = $meta['description'];
      } else {
        // Keep the existing description, only fix the category and any blanks.
        $cookie['category'] = $meta['category'];
        if (empty($cookie['provider'])) {
          $cookie['provider'] = $meta['provider'];
        }
        if (empty($cookie['duration'])) {
          $cookie['duration'] = $meta['duration'];
        }
      }
      $updated_names[] = $name;
    }
    unset($cookie);

    if (!empty($updated_names)) {
      update_option('kncc_cookies', $cookies, false);
    }

    return $updated_names;
  }

  public function process_scan() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    delete_transient('kncc_frontend_html');

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by array_map below.
    $raw_found = isset($_POST['found']) ? wp_unslash($_POST['found']) : array();
    // array_map() on a non-array is a TypeError on PHP 8.
    $found = is_array($raw_found) ? array_map('sanitize_text_field', $raw_found) : array();
    $existing = kncc_get_cookies();

    $known_names = array();
    foreach ($existing as $c) {
      if (isset($c['name'])) {
        $known_names[] = $c['name'];
      }
    }

    $auto_added = array();
    $unknown = array();
    $filtered = array();

    foreach ($found as $name) {
      $name = trim($name);
      if ($name === '' || in_array($name, $known_names, true)) {
        continue;
      }

      $meta = self::identify_cookie($name);

      if ($meta) {
        // Guard against third-party cookies that leaked into the admin's browser from a parent domain
        // or previous visit: verify that the provider's tracking script is actually on this site.
        if (!kncc_service_runs_on_site($meta['provider'])) {
          $filtered[] = array(
            'name'     => $name,
            'provider' => $meta['provider'],
          );
          $known_names[] = $name;
          continue;
        }
        $cookie = array(
          'name'        => $name,
          'provider'    => $meta['provider'],
          'category'    => $meta['category'],
          'duration'    => $meta['duration'],
          'description' => $meta['description'],
        );
        $existing[] = $cookie;
        $auto_added[] = $cookie;
      } else {
        $unknown[] = array(
          'name'        => $name,
          'provider'    => '',
          'category'    => 'necessary',
          'duration'    => '',
          'description' => '',
        );
      }

      $known_names[] = $name;
    }

    if (!empty($auto_added)) {
      update_option('kncc_cookies', $existing, false);
    }

    $sync = KNCC_Service_Cookies::sync_inferred_cookies();
    $inferred = $sync['added'];
    $stale = KNCC_Service_Cookies::find_stale_cookies();

    // Fresh, complete list of saved cookies after this scan persisted its
    // auto-added and inferred results. Lets the AI prompt include every saved
    // cookie without relying on stale page-load data.
    $all_saved = array_values(array_map(function ($c) {
      return array(
        'name'        => isset($c['name']) ? $c['name'] : '',
        'category'    => isset($c['category']) ? $c['category'] : 'necessary',
        'provider'    => isset($c['provider']) ? $c['provider'] : '',
        'duration'    => isset($c['duration']) ? $c['duration'] : '',
        'description' => isset($c['description']) ? $c['description'] : '',
      );
    }, kncc_get_localized_cookies()));

    // Built-in texts are shown in the language being edited; saving the table stores them in English again.
    wp_send_json_success(array(
      'auto_added'  => kncc_translate_cookie_texts($auto_added),
      'inferred'    => kncc_translate_cookie_texts($inferred),
      'unknown'     => $unknown,
      'filtered'    => $filtered,
      'stale'       => kncc_translate_cookie_texts($stale),
      'all_saved'   => $all_saved,
      'total_found' => count($found),
    ));
  }

  public static function get_incomplete_cookies() {
    $cookies = kncc_get_cookies();
    $incomplete = array();
    foreach ($cookies as $cookie) {
      if (empty($cookie['description'])) {
        $incomplete[] = $cookie['name'];
      }
    }
    return $incomplete;
  }

  public function cron_check_cookies() {
    try {
      KNCC_Service_Cookies::sync_inferred_cookies();

      $incomplete = self::get_incomplete_cookies();
      if (!empty($incomplete)) {
        update_option('kncc_incomplete_cookies', $incomplete, false);
      } else {
        delete_option('kncc_incomplete_cookies');
      }
      delete_option('kncc_cron_error');
    } catch (\Throwable $e) {
      update_option('kncc_cron_error', $e->getMessage(), false);
    }
  }
}
