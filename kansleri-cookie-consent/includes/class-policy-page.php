<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Policy_Page {

  public function __construct() {
    add_shortcode('kncc_cookie_policy', array($this, 'render_shortcode'));
  }

  /**
   * Assigns the WordPress Privacy Policy page as the cookie policy page when
   * none is selected yet. Never creates a new page — uses Settings → Privacy.
   *
   * @return int Assigned page ID, or 0 if none available.
   */
  public static function maybe_assign_privacy_page() {
    $settings = kncc_get_settings();

    if (!empty($settings['policy_page_id'])) {
      $existing = get_post($settings['policy_page_id']);
      if ($existing && $existing->post_status !== 'trash') {
        return (int) $settings['policy_page_id'];
      }
    }

    $privacy_page_id = (int) get_option('wp_page_for_privacy_policy');
    if ($privacy_page_id <= 0) {
      return 0;
    }

    $privacy_page = get_post($privacy_page_id);
    if (!$privacy_page || $privacy_page->post_status === 'trash') {
      return 0;
    }

    $settings['policy_page_id'] = $privacy_page_id;
    update_option('kncc_settings', $settings, false);

    return $privacy_page_id;
  }

  /**
   * Backward-compatible activation hook alias.
   */
  public static function maybe_create_page() {
    self::maybe_assign_privacy_page();
  }

  /**
   * Renders the [kncc_cookie_policy] shortcode.
   */
  public function render_shortcode() {
    $cookies = kncc_get_localized_cookies();
    $settings = kncc_get_localized_settings();
    $text_style_mode = (isset($settings['policy_style_mode']) && $settings['policy_style_mode'] === 'plugin') ? 'plugin' : 'theme';

    if (empty($cookies)) {
      return '<p>' . esc_html(kncc_text('policy_no_cookies', $settings)) . '</p>';
    }

    $categories = array();
    $cat_descriptions = array();
    foreach (array_keys(kncc_get_categories()) as $key) {
      $categories[$key] = kncc_text('category_name_' . $key, $settings);
      $cat_descriptions[$key] = kncc_text('policy_desc_' . $key, $settings);
    }

    $col = array(
      'cookie'   => kncc_text('policy_col_cookie', $settings),
      'provider' => kncc_text('policy_col_provider', $settings),
      'purpose'  => kncc_text('policy_col_purpose', $settings),
      'duration' => kncc_text('policy_col_duration', $settings),
    );

    $grouped = array();
    foreach ($cookies as $cookie) {
      $cat = isset($cookie['category']) ? $cookie['category'] : 'necessary';
      if (!isset($grouped[$cat])) {
        $grouped[$cat] = array();
      }
      $grouped[$cat][] = $cookie;
    }

    $status_categories = array();
    foreach ($categories as $key => $label) {
      if (!empty($grouped[$key])) {
        $status_categories[$key] = $label;
      }
    }

    ob_start();
    ?>
    <div class="kncc-policy kncc-typography--<?php echo esc_attr($text_style_mode); ?>">
      <?php if (!empty($status_categories)) : ?>
        <div class="kncc-policy__consent-status">
          <p class="kncc-policy__consent-status-heading"><strong><?php echo esc_html(kncc_text('policy_status_heading', $settings)); ?></strong></p>
          <div id="kncc-policy-consent-status"
               class="kncc-policy__consent-status-body"
               data-categories="<?php echo esc_attr(implode(',', array_keys($status_categories))); ?>"
               data-labels="<?php echo esc_attr(wp_json_encode($status_categories)); ?>">
          </div>
          <?php if (class_exists('KNCC_WP_Consent_API') && KNCC_WP_Consent_API::is_active()) : ?>
            <p class="kncc-policy__consent-status-note">
              <?php echo esc_html(kncc_text('policy_status_note', $settings)); ?>
            </p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="kncc-policy__edit-consent">
        <button type="button" class="kncc-btn kncc-btn--primary kncc-edit-consent-btn">
          <?php echo esc_html(kncc_text('policy_edit_button', $settings)); ?>
        </button>
      </div>
      <?php foreach ($categories as $key => $label) : ?>
        <?php if (empty($grouped[$key])) continue; ?>
        <div class="kncc-policy__section">
          <h3><?php echo esc_html($label); ?></h3>
          <p class="kncc-policy__cat-desc"><?php echo esc_html($cat_descriptions[$key]); ?></p>
          <table class="kncc-policy__table">
            <thead>
              <tr>
                <th><?php echo esc_html($col['cookie']); ?></th>
                <th><?php echo esc_html($col['provider']); ?></th>
                <th><?php echo esc_html($col['purpose']); ?></th>
                <th><?php echo esc_html($col['duration']); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grouped[$key] as $cookie) : ?>
                <tr>
                  <td data-label="<?php echo esc_attr($col['cookie']); ?>"><code><?php echo esc_html($cookie['name']); ?></code></td>
                  <td data-label="<?php echo esc_attr($col['provider']); ?>"><?php echo esc_html($cookie['provider'] ?? ''); ?></td>
                  <td data-label="<?php echo esc_attr($col['purpose']); ?>"><?php echo esc_html($cookie['description'] ?? ''); ?></td>
                  <td data-label="<?php echo esc_attr($col['duration']); ?>"><?php echo esc_html($cookie['duration'] ?? ''); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>

    </div>
    <?php
    return ob_get_clean();
  }
}
