<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Consent {

  public function __construct() {
    add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    add_action('wp_footer', array($this, 'render_banner'), 100);
  }

  public function enqueue_assets() {
    if (is_admin()) {
      return;
    }

    $settings = kncc_get_settings();
    $texts = kncc_get_localized_texts();

    wp_enqueue_style(
      'kncc-consent',
      KNCC_PLUGIN_URL . 'assets/consent.css',
      array(),
      kncc_asset_version('assets/consent.css')
    );

    // Depend on wp-consent-api when active so wp_set_consent is defined before consent.js runs.
    $deps = KNCC_WP_Consent_API::is_active() ? array('wp-consent-api') : array();

    wp_enqueue_script(
      'kncc-consent',
      KNCC_PLUGIN_URL . 'assets/consent.js',
      $deps,
      kncc_asset_version('assets/consent.js'),
      true
    );

    wp_add_inline_script('kncc-consent', 'window.knccConfig=' . wp_json_encode(array(
      'consentMode'    => KNCC_Consent_Mode::is_enabled($settings),
      'ajaxUrl'        => admin_url('admin-ajax.php'),
      'statsNonce'     => wp_create_nonce('kncc_stats'),
      'cookiePatterns' => kncc_get_cookie_clearing_patterns(),
      'wpConsentMap'   => KNCC_WP_Consent_API::is_active() ? array(
        'analytics'   => 'statistics',
        'marketing'   => 'marketing',
        'preferences' => 'preferences',
      ) : null,
      'policyStatus'   => array(
        'noChoice'     => kncc_text('status_no_choice', $texts),
        'allowed'      => kncc_text('status_allowed', $texts),
        'denied'       => kncc_text('status_denied', $texts),
        'alwaysActive' => kncc_text('status_always_active', $texts),
      ),
    )) . ';', 'before');

    wp_add_inline_style('kncc-consent', self::build_css_vars($settings));
  }

  /**
   * Build the :root CSS variable block from the styling-related settings.
   * Reused by the admin preview so both contexts stay in lockstep.
   */
  public static function build_css_vars($settings, $selector = ':root') {
    $defaults = kncc_get_default_settings();
    $get = function ($key) use ($settings, $defaults) {
      return isset($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $defaults[$key];
    };
    $primary   = $get('primary_color');
    $secondary = $get('secondary_color');
    return sprintf(
      '%s{--kncc-banner-bg:%s;--kncc-heading:%s;--kncc-body:%s;--kncc-primary:%s;--kncc-primary-hover:%s;--kncc-primary-text:%s;--kncc-secondary:%s;--kncc-secondary-hover:%s;--kncc-secondary-text:%s;}',
      $selector,
      esc_attr($get('banner_bg_color')),
      esc_attr($get('heading_color')),
      esc_attr($get('body_color')),
      esc_attr($primary),
      esc_attr(self::darken_color_static($primary, 15)),
      esc_attr($get('primary_text_color')),
      esc_attr($secondary),
      esc_attr(self::darken_color_static($secondary, 8)),
      esc_attr($get('secondary_text_color'))
    );
  }

  public function render_banner() {
    if (is_admin()) {
      return;
    }

    $settings = kncc_get_localized_texts();
    $style = $settings['banner_style'];
    $text_style_mode = (isset($settings['text_style_mode']) && $settings['text_style_mode'] === 'theme') ? 'theme' : 'plugin';

    $categories = array();
    $cat_descriptions = array();
    foreach (array_keys(kncc_get_categories()) as $key) {
      $categories[$key] = kncc_text('category_name_' . $key, $settings);
      $cat_descriptions[$key] = kncc_text('category_desc_' . $key, $settings);
    }

    $in_use = kncc_get_categories_in_use();
    $empty_mode = (isset($settings['empty_category_mode']) && $settings['empty_category_mode'] === 'hide') ? 'hide' : 'disable';
    $empty_notes = array(
      'analytics'   => kncc_text('empty_note_analytics', $settings),
      'marketing'   => kncc_text('empty_note_marketing', $settings),
      'preferences' => kncc_text('empty_note_preferences', $settings),
    );

    $policy_link = '';
    if (!empty($settings['policy_page_id'])) {
      $policy_page_id = (int) $settings['policy_page_id'];

      // Prefer the current-language translation; fall back to the default-language page if it is missing or unpublished.
      if (defined('ICL_LANGUAGE_CODE')) {
        // WPML
        $translated_id = apply_filters('wpml_object_id', $policy_page_id, 'page', false); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
        if ($translated_id && get_post_status($translated_id) === 'publish') {
          $policy_page_id = (int) $translated_id;
        } else {
          $wpml_default = apply_filters('wpml_default_language', ''); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
          if ($wpml_default) {
            $default_id = apply_filters('wpml_object_id', $policy_page_id, 'page', false, $wpml_default); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
            if ($default_id && get_post_status($default_id) === 'publish') {
              $policy_page_id = (int) $default_id;
            }
          }
        }
      } elseif (function_exists('pll_get_post')) {
        // Polylang
        $translated_id = pll_get_post($policy_page_id);
        if ($translated_id && get_post_status($translated_id) === 'publish') {
          $policy_page_id = (int) $translated_id;
        } elseif (function_exists('pll_default_language')) {
          $default_lang = pll_default_language('slug');
          if ($default_lang) {
            $default_id = pll_get_post($policy_page_id, $default_lang);
            if ($default_id && get_post_status($default_id) === 'publish') {
              $policy_page_id = (int) $default_id;
            }
          }
        }
      }

      if (get_post_status($policy_page_id) === 'publish') {
        $url = get_permalink($policy_page_id);
        if ($url) {
          $policy_link = $url;
        }
      }
    }
    // Only the modal style blocks the rest of the page, so only it may claim
    // aria-modal. Bar and corner banners sit alongside the page content.
    $is_modal = ($style === 'modal');
    ?>
    <div id="kncc-banner"
         class="kncc-banner kncc-banner--<?php echo esc_attr( $style ); ?> kncc-typography--<?php echo esc_attr($text_style_mode); ?>"
         role="<?php echo $is_modal ? 'dialog' : 'region'; ?>"
         <?php if ($is_modal) : ?>aria-modal="true"<?php endif; ?>
         data-kncc-modal="<?php echo $is_modal ? '1' : '0'; ?>"
         aria-label="<?php echo esc_attr(kncc_text('banner_label', $settings)); ?>"
         style="display:none;">

      <?php if ($style === 'modal') : ?>
        <div class="kncc-overlay"></div>
      <?php endif; ?>

      <div class="kncc-inner">
        <button type="button" class="kncc-banner-close" data-kncc="close" aria-label="<?php echo esc_attr($settings['close_text']); ?>">&times;</button>
        <div class="kncc-main">
          <h2 class="kncc-heading"><?php echo esc_html($settings['heading_text']); ?></h2>
          <p class="kncc-desc"><?php echo esc_html($settings['description_text']); ?>
            <?php if ($policy_link) : ?>
              <a href="<?php echo esc_url($policy_link); ?>" class="kncc-policy-link" target="_blank"><?php echo esc_html(kncc_text('policy_link_text', $settings)); ?><span class="kncc-sr-only"> (<?php esc_html_e('opens in a new tab', 'kansleri-cookie-consent'); ?>)</span></a>
            <?php endif; ?>
          </p>

          <div class="kncc-actions">
            <button type="button" class="kncc-btn kncc-btn--primary" data-kncc="accept"><?php echo esc_html($settings['accept_text']); ?></button>
            <button type="button" class="kncc-btn kncc-btn--secondary" data-kncc="reject"><?php echo esc_html($settings['reject_text']); ?></button>
            <button type="button" class="kncc-btn kncc-btn--link" data-kncc="toggle-details"><?php echo esc_html($settings['customize_text']); ?></button>
          </div>
        </div>

        <div class="kncc-details" style="display:none;">
          <?php foreach ($categories as $key => $label) :
            $is_necessary = ($key === 'necessary');
            $has_cookies  = !empty($in_use[$key]);
            if (!$is_necessary && !$has_cookies && $empty_mode === 'hide') {
              continue;
            }
            $is_locked = $is_necessary || (!$has_cookies && $empty_mode === 'disable');
            $cat_classes = 'kncc-category';
            if (!$has_cookies && !$is_necessary) {
              $cat_classes .= ' kncc-category--empty';
            }
            ?>
            <div class="<?php echo esc_attr($cat_classes); ?>">
              <label class="kncc-category__label">
                <input
                  type="checkbox"
                  class="kncc-category__check"
                  data-category="<?php echo esc_attr($key); ?>"
                  <?php
                  if ($is_necessary) {
                    echo 'checked disabled';
                  } elseif ($is_locked) {
                    echo 'disabled';
                  }
                  ?>
                />
                <span class="kncc-category__toggle"></span>
                <span class="kncc-category__name"><?php echo esc_html($label); ?></span>
              </label>
              <p class="kncc-category__desc"><?php echo esc_html($cat_descriptions[$key]); ?></p>
              <?php if (!$is_necessary && !$has_cookies && isset($empty_notes[$key])) : ?>
                <p class="kncc-category__empty-note"><?php echo esc_html($empty_notes[$key]); ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="kncc-details-actions">
            <button type="button" class="kncc-btn kncc-btn--primary" data-kncc="save"><?php echo esc_html($settings['save_text']); ?></button>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($settings['show_floating_btn'])) : ?>
      <button type="button"
              id="kncc-floating-btn"
              class="kncc-floating-btn kncc-typography--<?php echo esc_attr($text_style_mode); ?>"
              aria-label="<?php echo esc_attr(kncc_text('settings_link_text', $settings)); ?>"
              title="<?php echo esc_attr(kncc_text('settings_link_text', $settings)); ?>"
              style="display:none;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <circle cx="8" cy="9" r="1.5" fill="currentColor" stroke="none"/>
          <circle cx="15" cy="11" r="1" fill="currentColor" stroke="none"/>
          <circle cx="10" cy="15" r="1.5" fill="currentColor" stroke="none"/>
          <circle cx="15" cy="16" r="1" fill="currentColor" stroke="none"/>
        </svg>
      </button>
    <?php endif; ?>

    <?php
  }

  public static function darken_color_static($hex, $percent) {
    if (!is_string($hex) || !preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)) {
      return '#3D4872';
    }
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $diff = (int)(255 * max(0, min(100, $percent)) / 100);
    $r = max(0, hexdec(substr($hex, 0, 2)) - $diff);
    $g = max(0, hexdec(substr($hex, 2, 2)) - $diff);
    $b = max(0, hexdec(substr($hex, 4, 2)) - $diff);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
  }
}
