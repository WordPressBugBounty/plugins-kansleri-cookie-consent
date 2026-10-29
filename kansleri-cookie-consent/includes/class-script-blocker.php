<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Script_Blocker {

  /**
   * Category of a Google Tag Manager container: it may hold analytics and marketing tags alike,
   * so either consent loads it, and each tag inside then waits for its own category (Consent Mode
   * for Google tags, the injection guard for the others). consent.js treats a space-separated
   * list as "any of these".
   */
  const CONTAINER_CATEGORY = 'analytics marketing';

  /**
   * Known WordPress script handles mapped to consent categories.
   * Plugins that register scripts with these handles will be auto-blocked.
   */
  private static $known_handles = array(
    'google-analytics'           => 'analytics',
    'google_gtagjs'              => 'analytics',
    'ga-google-analytics'        => 'analytics',
    'monsterinsights-frontend-script' => 'analytics',
    'monsterinsights-tracking-script' => 'analytics',
    'exactmetrics-frontend-script' => 'analytics',
    'hotjar'                     => 'analytics',
    'hotjar-tracking'            => 'analytics',
    'plausible'                  => 'analytics',
    'plausible-analytics'        => 'analytics',
    'matomo'                     => 'analytics',
    'fathom'                     => 'analytics',
    'facebook-pixel'             => 'marketing',
    'facebook-jssdk'             => 'marketing',
    'meta-pixel'                 => 'marketing',
    'fbevents'                   => 'marketing',
    'linkedin-insight'           => 'marketing',
    'hubspot-tracking'           => 'marketing',
    'hs-analytics'               => 'marketing',
    'pinterest-tag'              => 'marketing',
    'tiktok-pixel'               => 'marketing',
    'twitter-pixel'              => 'marketing',
    'snap-pixel'                 => 'marketing',
    'google-ads'                 => 'marketing',
    'google-ads-conversion'      => 'marketing',
    // WooCommerce Order Attribution records where a visitor came from in sbjs_* cookies
    // (marketing cookies in the cookie scanner), on every page and before any choice.
    'sourcebuster-js'            => 'marketing',
    'wc-order-attribution'       => 'marketing',
  );

  /**
   * URL patterns that identify third-party tracking scripts.
   */
  private static $known_url_patterns = array(
    'googletagmanager.com/gtag'  => 'analytics',
    'googletagmanager.com/gtm.js' => self::CONTAINER_CATEGORY,
    'gtg/measurement.php'        => 'analytics',
    'google-analytics.com'       => 'analytics',
    'googleanalytics.com'        => 'analytics',
    'scripts.clarity.ms'         => 'analytics',
    'clarity.ms/tag'             => 'analytics',
    'hotjar.com'                 => 'analytics',
    'plausible.io'               => 'analytics',
    'matomo'                     => 'analytics',
    'connect.facebook.net'       => 'marketing',
    'fbevents.js'                => 'marketing',
    'snap.licdn.com'             => 'marketing',
    'ads.linkedin.com'           => 'marketing',
    'js.hs-scripts.com'          => 'marketing',
    'js.hs-analytics.net'        => 'marketing',
    'analytics.tiktok.com'       => 'marketing',
    'static.ads-twitter.com'     => 'marketing',
    'googleadservices.com'       => 'marketing',
    's.pinimg.com/ct'            => 'marketing',
    'sc-static.net/scevent'      => 'marketing',
    // Trustmary keeps a visitor ID, the landing page and conversions in localStorage
    // and reports widget views, so its reviews widget waits for analytics consent.
    'widget.trustmary.com'       => 'analytics',
    'embed.trustmary.com'        => 'analytics',
    // Advertising and analytics services that set visitor identifiers before any choice.
    'redditstatic.com/ads'       => 'marketing',  // Reddit Pixel
    'alb.reddit.com'             => 'marketing',
    'bat.bing.com'               => 'marketing',  // Microsoft Advertising (UET)
    'criteo.com'                 => 'marketing',
    'criteo.net'                 => 'marketing',
    'taboola.com'                => 'marketing',
    'klaviyo.com'                => 'marketing',
    'chimpstatic.com'            => 'marketing',  // Mailchimp pop-ups and forms
    'widget.trustpilot.com'      => 'marketing',  // Trustpilot TrustBox
    'cdn.usefathom.com'          => 'analytics',
    'cloudflareinsights.com'     => 'analytics',  // Cloudflare Web Analytics
    'mc.yandex.'                 => 'analytics',  // Yandex Metrica (.ru and .com)
    'cdn.segment.com'            => 'analytics',
    'cdn.segment.io'             => 'analytics',
    // Chat and booking widgets keep a visitor ID and session in cookies; the visitor has not
    // asked for them yet, so they wait for preference cookies.
    'widget.intercom.io'         => 'preferences',
    'js.intercomcdn.com'         => 'preferences',
    'client.crisp.chat'          => 'preferences',
    'assets.calendly.com'        => 'preferences',
  );

  /**
   * Built-in script URL patterns that show content where they are placed (e.g. a reviews
   * widget). When blocked in page HTML, a placeholder with an accept button is shown
   * in their place instead of an empty gap.
   */
  private static $placeholder_url_patterns = array(
    'widget.trustmary.com',
  );

  /**
   * Request cache for get_url_rules().
   */
  private static $url_rules = null;

  /**
   * Inline script content patterns mapped to consent categories.
   */
  private static $known_inline_patterns = array(
    "gtag('config'"   => 'analytics',
    'gtag("config"'   => 'analytics',
    "gtag('js'"       => 'analytics',
    'gtag("js"'       => 'analytics',
    "clarity("        => 'analytics',
    'clarity("'       => 'analytics',
    '_clarity'        => 'analytics',
    'hj('             => 'analytics',
    'plausible('      => 'analytics',
    '_paq.push'       => 'analytics',
    "fbq('init'"      => 'marketing',
    'fbq("init"'      => 'marketing',
    'fbq('            => 'marketing',
    '_linkedin_data_partner_id' => 'marketing',
    'ttq.load'        => 'marketing',
    'twq('            => 'marketing',
    'pintrk('         => 'marketing',
    'snaptr('         => 'marketing',
    '_hsq.push'       => 'marketing',
    "rdt('init'"      => 'marketing',
    'rdt("init"'      => 'marketing',
    'uetq'            => 'marketing',
    '_tfa.push'       => 'marketing',
    'criteo_q'        => 'marketing',
    '_learnq'         => 'marketing',
    'analytics.load(' => 'analytics',
    'intercomSettings' => 'preferences',
    '$crisp'          => 'preferences',
    'CRISP_WEBSITE_ID' => 'preferences',
    'Calendly.init'   => 'preferences',
  );

  /**
   * URL and inline patterns of the Google tag (gtag.js, also served through Site Kit's Google
   * tag gateway) and Google Tag Manager. With Advanced Consent Mode these are not blocked:
   * Consent Mode keeps them from setting cookies until the visitor accepts.
   */
  private static $google_tag_patterns = array(
    'googletagmanager.com/gtag',
    'googletagmanager.com/gtm.js',
    'gtg/measurement.php',
    "gtag('config'",
    'gtag("config"',
    "gtag('js'",
    'gtag("js"',
  );

  /**
   * Request cache for google_tags_load_before_consent().
   */
  private static $google_tags_unblocked = null;

  /**
   * Iframe URL patterns mapped to consent categories.
   */
  private static $iframe_url_patterns = array(
    'youtube.com/embed'          => 'marketing',
    'youtube-nocookie.com/embed' => 'marketing',
    'player.vimeo.com'           => 'marketing',
  );

  public function __construct() {
    add_action('wp_ajax_kncc_save_blocked_scripts', array($this, 'ajax_save_blocked_scripts'));
    add_action('wp_ajax_kncc_save_blocking_settings', array($this, 'ajax_save_blocking_settings'));

    if (is_admin()) {
      return;
    }

    // Before every other script in the head, so it is in place before any tag manager runs.
    add_action('wp_head', array($this, 'output_injection_guard'), -9999);
    add_filter('script_loader_tag', array($this, 'maybe_block_script'), 10, 3);
    add_filter('the_content', array($this, 'block_iframes_in_content'), 999);
    add_filter('embed_oembed_html', array($this, 'block_iframes_in_content'), 999, 1);
    add_action('template_redirect', array($this, 'start_output_buffer'), 0);
    add_filter('autoptimize_filter_js_exclude', array($this, 'autoptimize_js_exclusions'));
    add_filter('litespeed_optm_js_defer_exc', array($this, 'litespeed_js_exclusions'));
  }

  /**
   * LiteSpeed Cache's "Load JS Deferred" in Delayed mode holds every script back until the visitor
   * first scrolls or clicks, including this plugin's own, so a first-time visitor would see no
   * banner until then. Tell it to leave the consent scripts alone (the same list WP Rocket gets).
   *
   * @param string[]|mixed $exclusions Strings (script address or inline code fragments) to leave alone.
   * @return string[]
   */
  public function litespeed_js_exclusions($exclusions) {
    if (!is_array($exclusions)) {
      $exclusions = array();
    }
    foreach (array('kansleri-cookie-consent', 'knccConfig', 'knccGuard', 'kncc-consent', 'wp-consent-api', 'googlesitekit-consent-mode', 'google_gtagjs-js-consent-mode') as $pattern) {
      if (!in_array($pattern, $exclusions, true)) {
        $exclusions[] = $pattern;
      }
    }
    return $exclusions;
  }

  /**
   * Addresses and code fragments of every script this plugin holds back: the built-in patterns and
   * the site owner's "Block by address" rules, without those set to "Necessary (never blocked)".
   *
   * @return string[]
   */
  public static function get_exclusion_patterns() {
    $patterns = array();
    foreach (array_merge(array_keys(self::$known_url_patterns), array_keys(self::$known_inline_patterns)) as $pattern) {
      if (!self::is_unblocked_google_pattern($pattern)) {
        $patterns[] = $pattern;
      }
    }
    foreach (self::get_url_rules() as $rule) {
      if ($rule['category'] !== 'necessary') {
        $patterns[] = $rule['pattern'];
      }
    }
    return array_values(array_unique($patterns));
  }

  /**
   * Autoptimize merges scripts, inline ones too when "Also aggregate inline JS" is on, into one file
   * on the site's own domain. This plugin sees the page after that, so a tracker inside the file
   * can no longer be told apart from the site's own code and would run before consent. Autoptimize
   * leaves alone every script that contains one of the strings in its exclusion list.
   *
   * @param string|array $exclude Autoptimize's exclusion list: a comma separated string, or script => flag.
   * @return string|array
   */
  public function autoptimize_js_exclusions($exclude) {
    $patterns = self::get_exclusion_patterns();
    if (is_array($exclude)) {
      foreach ($patterns as $pattern) {
        if (!isset($exclude[$pattern])) {
          $exclude[$pattern] = '';
        }
      }
      return $exclude;
    }
    $existing = array_filter(array_map('trim', explode(',', (string) $exclude)));
    return implode(',', array_unique(array_merge($existing, $patterns)));
  }

  public static function get_blocked_scripts() {
    return get_option('kncc_blocked_scripts', array());
  }

  /**
   * Site owner's "Block by address" rules from the Script Blocking tab: a list of
   * array('pattern' => string, 'category' => string, 'placeholder' => bool).
   * Category 'necessary' means the address is never blocked, including built-in patterns.
   */
  public static function get_url_rules() {
    if (self::$url_rules === null) {
      $rules = get_option('kncc_blocked_url_patterns', array());
      self::$url_rules = is_array($rules) ? array_values(array_filter($rules, function ($rule) {
        return is_array($rule) && !empty($rule['pattern']) && !empty($rule['category']);
      })) : array();
    }
    return self::$url_rules;
  }

  /**
   * Validate and normalise "Block by address" rules posted from the Script Blocking tab.
   *
   * @return array{rules: array, error: string} The error names the first invalid address.
   */
  public static function sanitize_url_rules($raw) {
    $valid_categories = array('necessary', 'analytics', 'marketing', 'preferences');
    $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $rules = array();

    foreach ((array) $raw as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $pattern = isset($entry['pattern']) ? trim(sanitize_text_field((string) $entry['pattern'])) : '';
      // The scheme does not matter for matching, and leaving it out also covers //host/ URLs.
      $pattern = preg_replace('#^(?:[a-z][a-z0-9+.-]*:)?//#i', '', $pattern);
      if ($pattern === '') {
        continue;
      }
      if (strlen($pattern) < 4 || strlen($pattern) > 200 || preg_match('/[\s"\'<>]/', $pattern)) {
        /* translators: %s: the address the site owner entered */
        return array('rules' => array(), 'error' => sprintf(__('“%s” is not a usable address. Use at least 4 characters without spaces, for example widget.example.com.', 'kansleri-cookie-consent'), $pattern));
      }
      // A pattern that is part of the site's own host name would match every local script.
      if ($home_host !== '' && stripos($home_host, $pattern) !== false) {
        /* translators: %s: the address the site owner entered */
        return array('rules' => array(), 'error' => sprintf(__('“%s” would block every script on this site. Enter the address of the external service instead.', 'kansleri-cookie-consent'), $pattern));
      }
      $category = isset($entry['category']) ? sanitize_key((string) $entry['category']) : 'analytics';
      if (!in_array($category, $valid_categories, true)) {
        $category = 'analytics';
      }
      // Keyed by pattern, so a repeated address keeps its last row.
      $rules[strtolower($pattern)] = array(
        'pattern'     => $pattern,
        'category'    => $category,
        'placeholder' => !empty($entry['placeholder']) && $category !== 'necessary',
      );
    }

    return array('rules' => array_slice(array_values($rules), 0, 50), 'error' => '');
  }

  /**
   * The site owner's rule matching a script URL (or inline script body), or null. A root-relative
   * address (/wp-content/...) is matched with this site's host in front, as the browser and the
   * injection guard see it, so a rule naming the site's own address works on both.
   */
  private static function get_url_rule($haystack) {
    if ($haystack === '') {
      return null;
    }
    if (preg_match('#^/(?!/)\S*$#', $haystack)) {
      $haystack = (string) wp_parse_url(home_url('/'), PHP_URL_HOST) . $haystack;
    }
    foreach (self::get_url_rules() as $rule) {
      if (stripos($haystack, $rule['pattern']) !== false) {
        return $rule;
      }
    }
    return null;
  }

  /**
   * Whether a blocked script should get a placeholder in its place in page HTML.
   */
  private static function wants_placeholder($src, $content) {
    $rule = self::get_url_rule($src);
    if (!$rule && $src === '') {
      $rule = self::get_url_rule($content);
    }
    if ($rule) {
      return !empty($rule['placeholder']);
    }
    $haystack = $src !== '' ? $src : $content;
    foreach (self::$placeholder_url_patterns as $pattern) {
      if ($haystack !== '' && stripos($haystack, $pattern) !== false) {
        return true;
      }
    }
    return false;
  }

  /**
   * Whether raw HTML script blocking is enabled.
   */
  public static function is_html_blocking_enabled() {
    $settings = kncc_get_settings();
    $enabled = !isset($settings['block_html_scripts']) || !empty($settings['block_html_scripts']);
    return (bool) apply_filters('kncc_block_html_scripts', $enabled);
  }

  /**
   * Whether YouTube and Vimeo embeds are blocked until marketing consent.
   */
  public static function is_iframe_blocking_enabled() {
    $settings = kncc_get_settings();
    $enabled = !isset($settings['block_iframes']) || !empty($settings['block_iframes']);
    return (bool) apply_filters('kncc_block_iframes', $enabled);
  }

  /**
   * Whether the Google tag and Google Tag Manager load before consent (Advanced Consent Mode).
   * Cached for the request, because the HTML pass checks every pattern of every script.
   */
  public static function google_tags_load_before_consent() {
    if (self::$google_tags_unblocked === null) {
      self::$google_tags_unblocked = KNCC_Consent_Mode::is_advanced(kncc_get_settings());
    }
    return self::$google_tags_unblocked;
  }

  /**
   * Whether a URL or inline pattern is left unblocked because Google tags load before consent.
   */
  private static function is_unblocked_google_pattern($pattern) {
    return in_array($pattern, self::$google_tag_patterns, true) && self::google_tags_load_before_consent();
  }

  /**
   * Whether a script src is the Google tag or Google Tag Manager.
   */
  private static function is_google_tag_src($src) {
    foreach (self::$google_tag_patterns as $pattern) {
      if (stripos($src, $pattern) !== false) {
        return true;
      }
    }
    return false;
  }

  /**
   * Apply consent blocking attributes to a script tag string.
   *
   * $tag holds one or more complete <script> elements (script_loader_tag also passes the
   * translations and before/after inline scripts). Only each opening tag is rewritten: the
   * bodies must stay untouched, because loaders such as LinkedIn's and TikTok's contain
   * `x.type = "text/javascript"`, and a `type=` in the body must not count as the tag's type.
   */
  public static function apply_block_to_tag($tag, $category) {
    if (!$category || $category === 'necessary') {
      return $tag;
    }

    $blocked = preg_replace_callback(
      '/<script\b([^>]*)>(.*?)<\/script>/is',
      function ($m) use ($category) {
        $attrs = $m[1];
        $type  = self::get_script_type('<script' . $attrs . '>');

        // Data blocks, templates, and already-processed tags are not ours to retype.
        if (!self::is_executable_script_type($type) || stripos($attrs, 'data-kncc-category=') !== false) {
          return $m[0];
        }

        // WP Rocket's Delay JavaScript keeps the original type in data-rocket-type.
        $is_module = $type === 'module' || (bool) preg_match('/\sdata-rocket-type\s*=\s*["\']?module["\'\s>]/i', $attrs . '>');

        $attrs = preg_replace('/\s(?:type|data-rocket-type)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i', '', $attrs);

        // Delay JavaScript also moves src to data-rocket-src. consent.js copies the attributes
        // when it runs the script after consent, so it needs the real src back.
        $attrs = preg_replace('/\sdata-rocket-src(\s*=)/i', ' src$1', $attrs);

        // consent.js restores the module type when it re-runs the script.
        $restore = $is_module ? ' data-kncc-type="module"' : '';

        return '<script type="text/plain" data-kncc-category="' . esc_attr($category) . '"' . $restore . $attrs . '>' . $m[2] . '</script>';
      },
      $tag
    );

    return is_string($blocked) ? $blocked : $tag;
  }

  /**
   * Lowercased value of the type attribute of a script opening tag, or '' when it has none.
   */
  private static function get_script_type($open_tag) {
    if (preg_match('/\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $open_tag, $m)) {
      return strtolower(trim(implode('', array_slice($m, 1))));
    }
    return '';
  }

  /**
   * Whether a script type is executed by browsers (or delayed by WP Rocket). Anything else,
   * such as JSON, text/plain or text/html templates, is a data block that must be left alone.
   *
   * WP Rocket's Delay JavaScript runs before the HTML pass and retypes the scripts it will run
   * on the first user interaction as rocketlazyloadscript or, in current versions,
   * text/rocketlazyloadscript. Those are still trackers and must be blocked.
   */
  private static function is_executable_script_type($type) {
    return $type === ''
      || $type === 'module'
      || $type === 'rocketlazyloadscript'
      || $type === 'text/rocketlazyloadscript'
      || (bool) preg_match('#^(?:text|application)/(?:x-)?(?:javascript|ecmascript)$#', $type);
  }

  /**
   * Whether an inline script only sets Google consent state. Those must run before consent,
   * but a block that also loads a tracker (e.g. the GTM snippet pasted together with the
   * consent default) is still a tracker.
   */
  private static function is_consent_only_script($content) {
    if (!preg_match("/gtag\s*\(\s*['\"]consent['\"]/i", $content)
      || preg_match("/gtag\s*\(\s*['\"]config['\"]/i", $content)
      || !in_array(self::get_category_for_url($content), array(null, 'necessary'), true)
    ) {
      return false;
    }

    foreach (self::$known_inline_patterns as $pattern => $category) {
      if (stripos($content, $pattern) !== false) {
        return false;
      }
    }

    return true;
  }

  /**
   * Resolve consent category from a script src URL.
   */
  public static function get_category_for_url($src) {
    if (!$src) {
      return null;
    }

    // The site owner's rules come first, so they can also re-categorise or allow ('necessary')
    // an address the plugin knows.
    $rule = self::get_url_rule($src);
    if ($rule) {
      return $rule['category'];
    }

    $found = null;
    foreach (self::$known_url_patterns as $pattern => $category) {
      if (stripos($src, $pattern) !== false && !self::is_unblocked_google_pattern($pattern)) {
        if (in_array($pattern, self::$google_tag_patterns, true)) {
          $category = self::google_tag_category($src);
        }
        $found = self::stricter_category($found, $category);
      }
    }

    return $found;
  }

  /**
   * Category of a Google tag file or Google tag code. Consent Mode decides what a Google tag may
   * store, so the file only waits until the visitor has allowed something it serves: a tag that
   * can carry Google Analytics (a G- or GT- ID) waits for analytics; a container (GTM-) or an Ads
   * tag (AW-, DC-) for analytics or marketing. The same rule is in assets/guard.js.
   */
  private static function google_tag_category($text) {
    return preg_match('/\bGT?-[A-Z0-9]{4,}/i', (string) $text) ? 'analytics' : self::CONTAINER_CATEGORY;
  }

  /**
   * How long a category counts as in use after a script was last held back for it.
   */
  const BLOCKED_CATEGORY_TTL = 7776000; // 90 days

  /**
   * Remember that a script was held back for a category, so the banner offers that category's
   * switch even before Scan Cookies has registered a cookie for it. Written at most once a day
   * per category.
   */
  private static function remember_blocked_category($category) {
    static $remembered = array();
    // A container counts for analytics, as it did before it could also load on marketing consent.
    if ($category === self::CONTAINER_CATEGORY) {
      $category = 'analytics';
    }
    if (!in_array($category, array('analytics', 'marketing', 'preferences'), true) || isset($remembered[$category])) {
      return;
    }
    $remembered[$category] = true;

    $seen = get_option('kncc_blocked_categories_seen', array());
    if (!is_array($seen)) {
      $seen = array();
    }
    if (isset($seen[$category]) && (time() - (int) $seen[$category]) < DAY_IN_SECONDS) {
      return;
    }
    $seen[$category] = time();
    update_option('kncc_blocked_categories_seen', $seen, false);
  }

  /**
   * Categories in which a script was held back recently (see remember_blocked_category()).
   *
   * @return string[]
   */
  public static function get_recently_blocked_categories() {
    $seen = get_option('kncc_blocked_categories_seen', array());
    if (!is_array($seen)) {
      return array();
    }
    $recent = array();
    foreach ($seen as $category => $time) {
      if ((time() - (int) $time) < self::BLOCKED_CATEGORY_TTL) {
        $recent[] = $category;
      }
    }
    return $recent;
  }

  /**
   * The category that needs more consent. A block of code that mixes several services (what a
   * theme, a header-scripts plugin or a script optimiser can put together) matches patterns of
   * more than one category; it is held for the strictest, so it never runs on less consent than
   * its most invasive part needs.
   */
  private static function stricter_category($current, $candidate) {
    // "Any of analytics or marketing" needs the least: combined with anything else, the other wins.
    $rank = array(self::CONTAINER_CATEGORY => 1, 'preferences' => 2, 'analytics' => 3, 'marketing' => 4);
    // $current is null until a pattern has matched; null as an array offset is deprecated in PHP 8.5.
    $current_rank = is_string($current) && isset($rank[$current]) ? $rank[$current] : 0;
    $candidate_rank = is_string($candidate) && isset($rank[$candidate]) ? $rank[$candidate] : 0;
    return $candidate_rank > $current_rank ? $candidate : $current;
  }

  /**
   * Resolve consent category from inline script body content.
   */
  public static function get_category_for_inline($content) {
    if ($content === '') {
      return null;
    }

    if (self::is_consent_only_script($content)) {
      return null;
    }

    // With Advanced Consent Mode the Google patterns are skipped, so a block that also
    // contains another tracker is still blocked under that tracker's category.
    $found = null;
    foreach (self::$known_inline_patterns as $pattern => $category) {
      if (stripos($content, $pattern) !== false && !self::is_unblocked_google_pattern($pattern)) {
        if (in_array($pattern, self::$google_tag_patterns, true)) {
          $category = self::google_tag_category($content);
        }
        $found = self::stricter_category($found, $category);
      }
    }

    // Addresses inside the code count too (a loader that builds a script tag for a known service).
    $from_urls = self::get_category_for_url($content);
    if ($found === null) {
      return $from_urls;
    }
    return in_array($from_urls, array('analytics', 'marketing', 'preferences'), true) ? self::stricter_category($found, $from_urls) : $found;
  }

  /**
   * Whether a script tag should be left untouched by the HTML pass.
   */
  public static function should_skip_script_tag($tag, $content = '') {
    // Judge by the opening tag only; the script body can contain any of these strings.
    $open = preg_match('/^<script\b[^>]*>/i', $tag, $m) ? $m[0] : $tag;

    if (stripos($open, 'data-kncc-category=') !== false) {
      return true;
    }

    // text/plain, JSON, templates and other data blocks never execute.
    if (!self::is_executable_script_type(self::get_script_type($open))) {
      return true;
    }

    if (preg_match('/\bid\s*=\s*["\'][^"\']*(kncc|wp-consent-api)[^"\']*["\']/i', $open)) {
      return true;
    }

    if ($content !== '' && self::is_consent_only_script($content)) {
      return true;
    }

    return false;
  }

  /**
   * Intercept WordPress-enqueued scripts and block them if they match
   * a known handle, a known URL pattern, or a user-configured handle.
   */
  public function maybe_block_script($tag, $handle, $src) {
    $category = $this->get_handle_category($handle, $src, $tag);
    if (!$category || $category === 'necessary') {
      return $tag;
    }

    self::remember_blocked_category($category);
    return self::apply_block_to_tag($tag, $category);
  }

  /**
   * Determine the consent category for a given script handle and src. $tag is the printed
   * markup, with the handle's inline scripts.
   */
  private function get_handle_category($handle, $src, $tag = '') {
    // Never this plugin's own scripts, whatever a "Block by address" rule matches.
    if (strpos($handle, 'kncc') === 0) {
      return null;
    }

    $user_blocked = self::get_blocked_scripts();
    if (isset($user_blocked[$handle])) {
      return $user_blocked[$handle]['category'];
    }

    // Site Kit's google_gtagjs handles, or any handle loading the Google tag or GTM, e.g. a
    // known 'google-ads' handle pointing at gtag.js.
    $is_google_tag = strpos($handle, 'google_gtagjs') === 0 || self::is_google_tag_src((string) $src);
    if ($is_google_tag && self::google_tags_load_before_consent()) {
      return null;
    }

    if (strpos($handle, 'google_gtagjs') === 0) {
      // A tag with only Ads (AW-) or Floodlight (DC-) IDs serves marketing as much as analytics,
      // as the same file does in page HTML. Any Google Analytics ID, also in the inline config,
      // keeps it waiting for analytics.
      $text = (string) $src . ' ' . (string) $tag;
      return preg_match('/\b(?:AW|DC)-[A-Z0-9]{4,}/i', $text) && !preg_match('/\bGT?-[A-Z0-9]{4,}/i', $text) ? self::CONTAINER_CATEGORY : 'analytics';
    }

    if (isset(self::$known_handles[$handle])) {
      return self::$known_handles[$handle];
    }

    return self::get_category_for_url($src);
  }

  /**
   * Start output buffering for raw HTML script blocking.
   */
  public function start_output_buffer() {
    if (!$this->should_start_buffer()) {
      return;
    }

    ob_start(array($this, 'process_buffered_html'));
  }

  /**
   * Whether the current request should use HTML output buffering.
   */
  private function should_start_buffer() {
    if (!self::is_html_blocking_enabled()) {
      return false;
    }

    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
      return false;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
      return false;
    }

    if (is_feed() || is_robots() || is_trackback()) {
      return false;
    }

    if (function_exists('is_preview') && is_preview()) {
      return false;
    }

    $accept = isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Accept header checked for HTML only.
    if ($accept !== '' && stripos($accept, 'text/html') === false && stripos($accept, '*/*') === false) {
      return false;
    }

    return true;
  }

  /**
   * Address patterns for the injection guard (assets/guard.js): the site owner's rules, the
   * Google tag files, and every other known tracker with its category. Lowercase, as the guard
   * compares lowercased addresses, like stripos() does here.
   */
  public static function get_injection_guard_config() {
    $google = array();
    $patterns = array();
    foreach (self::$known_url_patterns as $pattern => $category) {
      if (in_array($pattern, self::$google_tag_patterns, true)) {
        $google[] = strtolower($pattern);
      } else {
        $patterns[] = array(strtolower($pattern), $category);
      }
    }
    $rules = array();
    foreach (self::get_url_rules() as $rule) {
      $rules[] = array(strtolower($rule['pattern']), $rule['category']);
    }
    return array(
      'rules'      => $rules,
      'google'     => $google,
      'holdGoogle' => !self::google_tags_load_before_consent(),
      'patterns'   => $patterns,
    );
  }

  /**
   * Print the injection guard first in the head. The page HTML pass cannot see scripts that
   * other code creates later, such as a Meta Pixel in a Google Tag Manager Custom HTML tag;
   * the guard holds those back in the browser until the visitor allows their category.
   */
  public function output_injection_guard() {
    if (!$this->should_start_buffer() || !apply_filters('kncc_block_injected_scripts', true)) {
      return;
    }
    $file = KNCC_PLUGIN_DIR . 'assets/guard.js';
    $code = is_readable($file) ? file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, printed inline so it runs before any tag manager.
    if (!$code) {
      return;
    }
    // Printed on every page: drop the whole-line // comments, which are for whoever reads the file.
    $code = preg_replace('#^[ \t]*//[^\r\n]*\R#m', '', $code);
    wp_print_inline_script_tag('window.knccGuard=' . wp_json_encode(self::get_injection_guard_config()) . ";\n" . $code, array('id' => 'kncc-guard'));
  }

  /**
   * Output buffer callback — rewrite tracking scripts and third-party iframes in the
   * final HTML. Iframes are handled here as well as on the_content because page
   * builders such as Bricks and Elementor render embeds outside the content filter.
   * Iframes already carrying data-kncc-category are skipped, so the two passes do not
   * conflict.
   */
  public function process_buffered_html($html) {
    if (!is_string($html) || $html === '') {
      return $html;
    }

    if (stripos($html, '<html') === false && stripos($html, '<!DOCTYPE') === false) {
      return $html;
    }

    return $this->block_iframes_in_content(self::block_scripts_in_html($html));
  }

  /**
   * Scan HTML for raw script tags and block known tracking scripts.
   */
  public static function block_scripts_in_html($html) {
    if (!preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_OFFSET_CAPTURE)) {
      return $html;
    }

    $offset_shift = 0;

    foreach ($matches[0] as $match) {
      $tag = $match[0];
      $position = $match[1] + $offset_shift;

      // A script delayed by WP Rocket carries its URL in data-rocket-src instead of src.
      $src = '';
      if (preg_match('/\s(?:data-rocket-)?src\s*=\s*["\']([^"\']*)["\']/i', $tag, $src_match)) {
        $src = $src_match[1];
      } elseif (preg_match('/^<script\b[^>]*?\s(?:data-rocket-)?src\s*=\s*([^\s"\'>]+)/i', $tag, $src_match)) {
        // Unquoted attribute, as an HTML minifier writes it. Only the opening tag is looked at,
        // so code such as `src = url;` inside the script body is not mistaken for an address.
        $src = $src_match[1];
      }

      $content = '';
      if (preg_match('/<script\b[^>]*>(.*?)<\/script>/is', $tag, $body_match)) {
        $content = $body_match[1];
      }

      if (self::should_skip_script_tag($tag, $content)) {
        continue;
      }

      $category = self::get_category_for_url($src);
      if (!$category) {
        $category = self::get_category_for_inline($content);
      }

      if (!$category || $category === 'necessary') {
        continue;
      }

      self::remember_blocked_category($category);
      $blocked = self::apply_block_to_tag($tag, $category);
      // A widget script shows its content where it sits, so the placeholder goes right after it.
      // consent.js removes the placeholder when the category is granted.
      if ($blocked !== $tag && self::wants_placeholder($src, $content)) {
        $blocked .= self::get_placeholder_html($category, '', 'kncc-script-placeholder');
      }
      $html = substr_replace($html, $blocked, $position, strlen($tag));
      $offset_shift += strlen($blocked) - strlen($tag);
    }

    return $html;
  }

  /**
   * Detect which enqueued scripts are currently registered and match known patterns.
   * Used in admin to show auto-detected scripts.
   */
  public static function detect_registered_scripts() {
    $detected = array();

    foreach (self::$known_handles as $handle => $category) {
      $detected[$handle] = array(
        'handle'   => $handle,
        'category' => $category,
        'source'   => 'known_handle',
      );
    }

    return $detected;
  }

  /**
   * Block third-party iframes (YouTube, Vimeo) until the visitor grants the matching
   * consent category.
   *
   * Every iframe is blocked regardless of the visitor's current consent, so the markup
   * is identical for everyone and safe to store in a page cache. consent.js restores
   * the real src on load for visitors who already consented. Varying this by cookie
   * would let a cache hand an unblocked embed to a visitor who never consented.
   *
   * Site owners can turn embed blocking off on the Script Blocking tab.
   */
  public function block_iframes_in_content($content) {
    if (!is_string($content) || $content === '') {
      return $content;
    }

    // Auto-excerpts strip the markup, so the placeholder text would become the excerpt,
    // and feed readers cannot run the accept button.
    if (doing_filter('get_the_excerpt') || is_feed()) {
      return $content;
    }

    $has_elementor_video = stripos($content, 'data-widget_type="video.') !== false;
    if (!$has_elementor_video && stripos($content, '<iframe') === false) {
      return $content;
    }

    if (!self::is_iframe_blocking_enabled()) {
      return $content;
    }

    if ($has_elementor_video && !self::is_elementor_editor_request()) {
      $content = $this->block_elementor_videos($content);
    }

    if (!preg_match_all('/<iframe\b[^>]*>/i', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      return $content;
    }

    $offset_shift = 0;

    foreach ($matches as $match) {
      $iframe_tag = $match[0][0];
      $position   = $match[0][1] + $offset_shift;

      // Skip iframes already processed (prevents double-processing via embed_oembed_html + the_content).
      if (stripos($iframe_tag, 'data-kncc-category') !== false) {
        continue;
      }

      if (!preg_match('/\ssrc\s*=\s*["\']([^"\']*)/i', $iframe_tag, $src_match)) {
        continue;
      }
      $src = $src_match[1];

      $category = $this->get_iframe_category($src);
      if (!$category) {
        continue;
      }

      $blocked_tag = preg_replace(
        '/(\s)src\s*=\s*(["\'])/i',
        '$1data-kncc-src=$2',
        $iframe_tag
      );
      $blocked_tag = str_replace('<iframe', '<iframe data-kncc-category="' . esc_attr($category) . '" class="kncc-blocked-iframe"', $blocked_tag);

      $replacement = self::get_placeholder_html($category, $this->youtube_thumbnail_url($src)) . $blocked_tag;

      $content = substr_replace($content, $replacement, $position, strlen($iframe_tag));
      $offset_shift += strlen($replacement) - strlen($iframe_tag);
    }

    return $content;
  }

  /**
   * Elementor's Video widget builds its YouTube player in JavaScript, so the markup holds no
   * iframe for block_iframes_in_content() to catch, and its lightbox opens one on click.
   * Renaming data-widget_type stops Elementor from starting the player, the lightbox trigger
   * is renamed too, any inner (Vimeo) iframe is parked like other embeds, and the placeholder
   * goes on top. After consent, consent.js builds the player or restores the lightbox.
   */
  private function block_elementor_videos($content) {
    if (!preg_match_all('/<div\b[^>]*\sdata-widget_type="video\.[a-z0-9_-]+"[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
      return $content;
    }

    // Last to first, so earlier offsets stay valid.
    for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
      list($open_tag, $position) = $matches[0][$i];

      $settings = null;
      if (preg_match('/\sdata-settings="([^"]*)"/i', $open_tag, $settings_match)) {
        $settings = json_decode(html_entity_decode($settings_match[1], ENT_QUOTES, 'UTF-8'), true);
      }
      $video_type = is_array($settings) && isset($settings['video_type']) ? $settings['video_type'] : '';

      $thumb_url = '';
      if ($video_type === 'youtube') {
        $category = $this->get_iframe_category('https://www.youtube.com/embed/');
        $video_id = isset($settings['youtube_url']) ? $this->youtube_video_id($settings['youtube_url']) : '';
        if ($video_id) {
          $thumb_url = $this->youtube_thumbnail_url('https://www.youtube.com/embed/' . $video_id);
        }
      } elseif ($video_type === 'vimeo') {
        $category = $this->get_iframe_category('https://player.vimeo.com/');
      } else {
        continue; // Self-hosted and Dailymotion videos are left to Elementor.
      }
      if (!$category) {
        continue;
      }

      // The widget's own markup runs until the next Elementor element starts.
      $start = $position + strlen($open_tag);
      $end = preg_match('/<div\b[^>]*\sdata-element_type=/i', $content, $next, PREG_OFFSET_CAPTURE, $start)
        ? $next[0][1]
        : strlen($content);
      $inner = substr($content, $start, $end - $start);

      $inner = preg_replace('/\sdata-elementor-(open-lightbox|lightbox)=/i', ' data-kncc-$1=', $inner);

      $inner = preg_replace_callback('/<iframe\b[^>]*>/i', function ($m) use ($category) {
        if (stripos($m[0], 'data-kncc-category') !== false) {
          return $m[0];
        }
        $tag = preg_replace('/(\s)src\s*=\s*(["\'])/i', '$1data-kncc-src=$2', $m[0]);
        return str_replace('<iframe', '<iframe data-kncc-category="' . esc_attr($category) . '" class="kncc-blocked-iframe"', $tag);
      }, $inner);

      $placeholder = self::get_placeholder_html($category, $thumb_url);
      $inner = preg_replace_callback('/<div\b[^>]*\bclass="[^"]*\belementor-wrapper\b[^"]*"[^>]*>/i', function ($m) use ($placeholder) {
        return $m[0] . $placeholder;
      }, $inner, 1);

      $open_tag = preg_replace(
        '/\sdata-widget_type="/i',
        ' data-kncc-category="' . esc_attr($category) . '" data-kncc-widget_type="',
        $open_tag,
        1
      );

      $content = substr_replace($content, $open_tag . $inner, $position, $end - $position);
    }

    return $content;
  }

  /**
   * Whether this request renders Elementor's editor or its preview frame, where the Video
   * widget must keep working so it can be edited.
   */
  private static function is_elementor_editor_request() {
    if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance)) {
      return false;
    }
    $elementor = \Elementor\Plugin::$instance;
    if (isset($elementor->preview) && method_exists($elementor->preview, 'is_preview_mode') && $elementor->preview->is_preview_mode()) {
      return true;
    }
    return isset($elementor->editor) && method_exists($elementor->editor, 'is_edit_mode') && $elementor->editor->is_edit_mode();
  }

  /**
   * Placeholder shown in place of a blocked embed, with an accept button for its category.
   */
  private static function get_placeholder_html($category, $thumb_url = '', $extra_class = '') {
    $texts = self::get_placeholder_texts($category);

    $thumb_html = $thumb_url
      ? '<img class="kncc-iframe-placeholder__thumb" src="' . esc_url($thumb_url) . '" alt="" loading="lazy" referrerpolicy="no-referrer" />'
      : '';

    $classes = 'kncc-iframe-placeholder' . ($thumb_url ? ' kncc-iframe-placeholder--has-thumb' : '') . ($extra_class ? ' ' . $extra_class : '');

    return '<div class="' . esc_attr($classes) . '" data-kncc-category="' . esc_attr($category) . '">'
      . $thumb_html
      . '<div class="kncc-iframe-placeholder__overlay">'
      . '<p>' . esc_html($texts['placeholder']) . '</p>'
      . '<button type="button" class="kncc-btn kncc-btn--primary kncc-iframe-accept" data-kncc-iframe-accept="' . esc_attr($category) . '">'
      . esc_html($texts['accept'])
      . '</button>'
      . '</div></div>';
  }

  /**
   * Placeholder message and accept button text for a category, all editable on the General
   * tab. Marketing keeps the iframe_* settings that videos have always used.
   */
  private static function get_placeholder_texts($category) {
    static $texts = array();
    if (isset($texts[$category])) {
      return $texts[$category];
    }

    $settings = kncc_get_localized_texts();
    if ($category === 'analytics' || $category === 'preferences') {
      $texts[$category] = array(
        'placeholder' => kncc_text('placeholder_text_' . $category, $settings),
        'accept'      => kncc_text('placeholder_accept_' . $category, $settings),
      );
    } else {
      $texts[$category] = array(
        'placeholder' => !empty($settings['iframe_placeholder_text'])
          ? $settings['iframe_placeholder_text']
          : __('This content is blocked because it requires marketing cookies.', 'kansleri-cookie-consent'),
        'accept'      => !empty($settings['iframe_accept_text'])
          ? $settings['iframe_accept_text']
          : __('Accept marketing cookies', 'kansleri-cookie-consent'),
      );
    }

    return $texts[$category];
  }

  /**
   * YouTube video ID from a watch, youtu.be, embed, shorts or live URL.
   */
  private function youtube_video_id($url) {
    if (preg_match('#(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:(?:watch)?\?(?:.*&)?vi?=|(?:embed|v|vi|shorts|live)/))([A-Za-z0-9_-]{11})#i', (string) $url, $m)) {
      return $m[1];
    }
    return '';
  }

  /**
   * Determine the consent category for an iframe src URL.
   */
  private function get_iframe_category($src) {
    foreach (self::$iframe_url_patterns as $pattern => $category) {
      if (stripos($src, $pattern) !== false) {
        return $category;
      }
    }
    return null;
  }

  /**
   * For a YouTube embed URL, return the public thumbnail URL.
   */
  private function youtube_thumbnail_url($url) {
    if (preg_match('#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#i', $url, $m)) {
      return 'https://i.ytimg.com/vi/' . $m[1] . '/mqdefault.jpg';
    }
    return '';
  }

  public function ajax_save_blocking_settings() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    // "Block by address" rules. admin.js sends url_rules_present with every save, so a request
    // without it comes from an admin page loaded before this setting existed and keeps the rules.
    $url_rules = null;
    if (isset($_POST['url_rules_present'])) {
      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_url_rules()
      $raw_rules = isset($_POST['url_rules']) ? wp_unslash($_POST['url_rules']) : array();
      $result = self::sanitize_url_rules(is_array($raw_rules) ? $raw_rules : array());
      if ($result['error'] !== '') {
        wp_send_json_error($result['error']);
      }
      $url_rules = $result['rules'];
    }

    $current = kncc_get_settings();
    $current['block_html_scripts'] = !empty($_POST['block_html_scripts']);
    // admin.js always sends block_iframes as '1' or '0'. A request without it comes from an
    // admin page loaded before this setting existed, and must not switch embed blocking off.
    if (isset($_POST['block_iframes'])) {
      $current['block_iframes'] = sanitize_key(wp_unslash($_POST['block_iframes'])) === '1';
    }
    update_option('kncc_settings', $current, false);
    if ($url_rules !== null) {
      update_option('kncc_blocked_url_patterns', $url_rules, false);
      self::$url_rules = null;
    }
    delete_transient('kncc_frontend_html');

    wp_send_json_success(array('message' => __('Script blocking settings saved.', 'kansleri-cookie-consent')));
  }

  public function ajax_save_blocked_scripts() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    $valid_categories = array('necessary', 'analytics', 'marketing', 'preferences');

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below
    $raw_blocked = isset($_POST['blocked_scripts']) ? wp_unslash($_POST['blocked_scripts']) : array();
    $blocked = array();
    if (is_array($raw_blocked)) {
      foreach ($raw_blocked as $entry) {
        $handle = isset($entry['handle']) ? sanitize_key($entry['handle']) : '';
        if ($handle === '') {
          continue;
        }
        $category = isset($entry['category']) ? sanitize_key($entry['category']) : 'analytics';
        if (!in_array($category, $valid_categories, true)) {
          $category = 'analytics';
        }
        $blocked[$handle] = array(
          'handle'   => $handle,
          'category' => $category,
        );
      }
    }
    update_option('kncc_blocked_scripts', $blocked, false);

    wp_send_json_success(array('message' => __('Script blocking settings saved.', 'kansleri-cookie-consent')));
  }
}
