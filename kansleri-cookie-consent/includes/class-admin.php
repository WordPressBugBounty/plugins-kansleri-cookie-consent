<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Admin {

  /**
   * Cookies added when the Cookies tab was opened, for the notice on that tab.
   */
  private $cookies_synced_count = 0;

  public function __construct() {
    add_action('admin_menu', array($this, 'add_menu'));
    add_action('load-settings_page_kansleri-cookie-consent', array($this, 'load_page'));
    add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    add_action('wp_ajax_kncc_save_settings', array($this, 'ajax_save_settings'));
    add_action('wp_ajax_kncc_save_cookies', array($this, 'ajax_save_cookies'));
    add_action('wp_ajax_kncc_remove_cookies', array($this, 'ajax_remove_cookies'));
    add_action('wp_ajax_kncc_export_consent_log', array($this, 'ajax_export_consent_log'));
    add_action('wp_ajax_kncc_purge_consent_log', array($this, 'ajax_purge_consent_log'));
  }

  public function add_menu() {
    add_options_page(
      __('Cookie Consent', 'kansleri-cookie-consent'),
      __('Cookie Consent', 'kansleri-cookie-consent'),
      'manage_options',
      'kansleri-cookie-consent',
      array($this, 'render_page')
    );
  }

  /**
   * Runs before the plugin page is printed and its scripts are set up.
   */
  public function load_page() {
    if (!current_user_can('manage_options')) {
      return;
    }

    // The buttons on the notice about the cookies that 1.8.10 added by mistake.
    if (isset($_GET['kncc_guard_notice'])) {
      check_admin_referer('kncc_guard_notice');
      if (sanitize_key($_GET['kncc_guard_notice']) === 'restore') {
        KNCC_Service_Cookies::restore_guard_removed_cookies();
      }
      delete_option('kncc_guard_removed_cookies');
      delete_option('kncc_guard_kept_cookies');
      wp_safe_redirect(remove_query_arg(array('kncc_guard_notice', '_wpnonce')));
      exit;
    }

    // Opening the Cookies tab adds the cookies of the services found on the site. This runs
    // before the scripts get the cookie list (also used for the AI prompt), so it is current.
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigational tab parameter
    if ($active_tab === 'cookies') {
      $sync = KNCC_Service_Cookies::sync_inferred_cookies();
      $this->cookies_synced_count = count($sync['added']);
    }
  }

  public function enqueue_assets($hook) {
    if ($hook !== 'settings_page_kansleri-cookie-consent') {
      return;
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigational tab parameter

    $admin_script_deps = array();
    if ($active_tab === 'general') {
      wp_enqueue_style('wp-color-picker');
      wp_enqueue_style(
        'kncc-consent',
        KNCC_PLUGIN_URL . 'assets/consent.css',
        array(),
        kncc_asset_version('assets/consent.css')
      );
      $admin_script_deps[] = 'wp-color-picker';
    }

    wp_enqueue_style(
      'kncc-admin',
      KNCC_PLUGIN_URL . 'assets/admin.css',
      array(),
      kncc_asset_version('assets/admin.css')
    );

    wp_enqueue_script(
      'kncc-admin',
      KNCC_PLUGIN_URL . 'assets/admin.js',
      $admin_script_deps,
      kncc_asset_version('assets/admin.js'),
      true
    );

    wp_localize_script('kncc-admin', 'knccAdmin', array(
      'ajaxUrl'  => admin_url('admin-ajax.php'),
      'nonce'    => wp_create_nonce('kncc_admin'),
      'homeUrl'  => home_url('/'),
      'strings'  => array(
        'saved'        => __('Settings saved.', 'kansleri-cookie-consent'),
        'error'        => __('An error occurred.', 'kansleri-cookie-consent'),
        'scanning'     => __('Scanning...', 'kansleri-cookie-consent'),
        'scanDone'     => __('Scan complete.', 'kansleri-cookie-consent'),
        'noNew'        => __('No new cookies found.', 'kansleri-cookie-consent'),
        'confirmDelete'=> __('Remove this cookie?', 'kansleri-cookie-consent'),
        'scanBtn'      => __('Scan Cookies', 'kansleri-cookie-consent'),
        'invalidJson'  => __('Invalid JSON format. Please import a valid cookie array.', 'kansleri-cookie-consent'),
        'importDone'   => __('Cookies imported into the table. Save cookies to persist changes.', 'kansleri-cookie-consent'),
        'importEmpty'  => __('No valid cookies found in imported JSON.', 'kansleri-cookie-consent'),
        'exportEmpty'  => __('No cookie rows available to export.', 'kansleri-cookie-consent'),
        'promptCopied' => __('AI prompt copied to clipboard.', 'kansleri-cookie-consent'),
        'promptFailed' => __('Could not copy prompt automatically. Select all and copy from the box below.', 'kansleri-cookie-consent'),
        'promptBoxLabel' => __('AI prompt (copied to clipboard — or select all and copy manually):', 'kansleri-cookie-consent'),
        'policyPromptBoxLabel' => __('Policy page AI prompt (copied to clipboard — or select all and copy manually):', 'kansleri-cookie-consent'),
        'promptSelectManually' => __('Prompt ready below — select all (Ctrl+A) inside the box and copy (Ctrl+C).', 'kansleri-cookie-consent'),
        'allDone'      => __('All cookies are done! Every cookie has a description.', 'kansleri-cookie-consent'),
        'noCookies'    => __('No cookies registered yet.', 'kansleri-cookie-consent'),
        'removeTitle'  => __('Remove', 'kansleri-cookie-consent'),
        'confirmPurge' => __('This will permanently delete all consent log records and statistics. Continue?', 'kansleri-cookie-consent'),
        'purged'       => __('All consent log records and statistics have been purged.', 'kansleri-cookie-consent'),
        'policyCopied' => __('AI prompt for policy page copied to clipboard.', 'kansleri-cookie-consent'),
        'cssCopied'    => __('CSS template copied to clipboard.', 'kansleri-cookie-consent'),
        /* translators: %d: number of known cookies added by the scanner */
        'knownAdded'   => __('%d known cookie(s) added automatically:', 'kansleri-cookie-consent'),
        /* translators: %d: number of unknown cookies found by the scanner */
        'unknownAdded' => __('%d unknown cookie(s) added — please set their category and description below.', 'kansleri-cookie-consent'),
        'unknownLabel' => __('Unknown', 'kansleri-cookie-consent'),
        'unknownDesc'  => __('Added — categorize and describe in the table below', 'kansleri-cookie-consent'),
        /* translators: %d: number of cookies inferred from detected tracking scripts */
        'inferredAdded' => __('%d cookie(s) inferred from detected scripts on your site:', 'kansleri-cookie-consent'),
        'inferredNote'  => __('These cookies are expected from tracking scripts on your site, even when blocked until consent.', 'kansleri-cookie-consent'),
        /* translators: %d: number of browser cookies skipped because no matching script was found */
        'filteredSkipped' => __('%d browser cookie(s) skipped — found in your browser but no matching script on this site:', 'kansleri-cookie-consent'),
        'staleTitle'      => __('Possibly removed', 'kansleri-cookie-consent'),
        'staleDesc'       => __('These cookies are still in your list, but their tracking scripts were not found on the homepage. Review and remove any that are no longer used.', 'kansleri-cookie-consent'),
        /* translators: %d: number of cookies to remove */
        'confirmStaleRemove' => __('Remove %d cookie(s) from the list? This cannot be undone automatically.', 'kansleri-cookie-consent'),
        'staleRemoved'    => __('Selected cookies removed from the list.', 'kansleri-cookie-consent'),
        'removeStaleBtn'  => __('Remove selected cookies', 'kansleri-cookie-consent'),
        'blockingSaved'  => __('Script blocking settings saved.', 'kansleri-cookie-consent'),
        'locale'       => get_locale(),
        'knccLang'     => kncc_get_current_language(),
      ),
      'cookies'  => array_values(array_map(function ($c) {
        return array(
          'name'        => isset($c['name']) ? $c['name'] : '',
          'category'    => isset($c['category']) ? $c['category'] : 'necessary',
          'provider'    => isset($c['provider']) ? $c['provider'] : '',
          'duration'    => isset($c['duration']) ? $c['duration'] : '',
          'description' => isset($c['description']) ? $c['description'] : '',
        );
      }, kncc_get_localized_cookies())),
    ));

    if ($active_tab === 'policy') {
      $categories = kncc_get_categories();
      $cat_summary = array();
      foreach ($categories as $key => $data) {
        $cat_summary[] = $key . ': ' . $data['description'];
      }
      wp_add_inline_script('kncc-admin', 'window.knccPolicyPromptData=' . wp_json_encode(array(
        'siteName'   => get_bloginfo('name'),
        'siteUrl'    => home_url('/'),
        'locale'     => get_locale(),
        'adminEmail' => get_option('admin_email'),
        'categories' => $cat_summary,
      )) . ';', 'before');
    }
  }

  public function render_page() {
    // Load settings and cookies with per-language overrides for the admin UI.
    $settings = kncc_get_localized_settings();
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigational tab parameter

    $cookies_synced_count = $this->cookies_synced_count;

    $cookies = kncc_get_localized_cookies();
    $pages = get_pages(array('post_status' => 'publish,draft'));
    ?>
    <div class="wrap kncc-wrap">
      <h1><?php esc_html_e('Cookie Consent', 'kansleri-cookie-consent'); ?></h1>

      <?php $this->render_status_label(); ?>

      <nav class="nav-tab-wrapper kncc-tabs">
        <a href="?page=kansleri-cookie-consent&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
          <?php esc_html_e('General', 'kansleri-cookie-consent'); ?>
        </a>
        <a href="?page=kansleri-cookie-consent&tab=cookies" class="nav-tab <?php echo $active_tab === 'cookies' ? 'nav-tab-active' : ''; ?>">
          <?php esc_html_e('Cookies', 'kansleri-cookie-consent'); ?>
        </a>
        <a href="?page=kansleri-cookie-consent&tab=policy" class="nav-tab <?php echo $active_tab === 'policy' ? 'nav-tab-active' : ''; ?>">
          <?php esc_html_e('Policy Page', 'kansleri-cookie-consent'); ?>
        </a>
        <a href="?page=kansleri-cookie-consent&tab=integration" class="nav-tab <?php echo $active_tab === 'integration' ? 'nav-tab-active' : ''; ?>">
          <?php esc_html_e('Integration', 'kansleri-cookie-consent'); ?>
        </a>
        <a href="?page=kansleri-cookie-consent&tab=blocking" class="nav-tab <?php echo $active_tab === 'blocking' ? 'nav-tab-active' : ''; ?>">
          <?php esc_html_e('Script Blocking', 'kansleri-cookie-consent'); ?>
        </a>
        <a href="?page=kansleri-cookie-consent&tab=statistics" class="nav-tab <?php echo $active_tab === 'statistics' ? 'nav-tab-active' : ''; ?>">
          <?php esc_html_e('Statistics', 'kansleri-cookie-consent'); ?>
        </a>
        <a href="?page=kansleri-cookie-consent&tab=documentations" class="nav-tab <?php echo $active_tab === 'documentations' ? 'nav-tab-active' : ''; ?>">
          <?php esc_html_e('Documentations', 'kansleri-cookie-consent'); ?>
        </a>
      </nav>

      <?php
      // Only the tabs whose settings are saved per language say which language this is.
      $kncc_per_language_tab = in_array($active_tab, array('general', 'cookies', 'policy'), true);
      $kncc_current_lang = $kncc_per_language_tab ? kncc_get_current_language() : '';
      $kncc_has_multilingual = $kncc_per_language_tab && (function_exists('pll_current_language') || defined('ICL_LANGUAGE_CODE'));
      if ($kncc_current_lang) :
      ?>
      <div class="notice notice-warning inline" style="margin:16px 0 16px;padding:10px 14px;">
        <?php
        printf(
          /* translators: %s: language code */
          esc_html__('Editing language: %s. Saves apply to this language only.', 'kansleri-cookie-consent'),
          '<strong>' . esc_html(strtoupper($kncc_current_lang)) . '</strong>'
        );
        ?>
      </div>
      <?php elseif ($kncc_has_multilingual) : ?>
      <div class="notice notice-info inline" style="margin:16px 0 16px;padding:10px 14px;">
        <?php esc_html_e('Editing the default language. Other languages have their own texts.', 'kansleri-cookie-consent'); ?>
      </div>
      <?php endif; ?>

      <?php
      // What the update did with the cookies that 1.8.10 added by mistake, until the owner has seen it.
      $kncc_removed = get_option('kncc_guard_removed_cookies');
      $kncc_kept = get_option('kncc_guard_kept_cookies');
      $kncc_removed = (!empty($kncc_removed) && is_array($kncc_removed)) ? array_filter(wp_list_pluck(array_filter($kncc_removed, 'is_array'), 'name')) : array();
      $kncc_kept = (!empty($kncc_kept) && is_array($kncc_kept)) ? $kncc_kept : array();
      if ($kncc_removed || $kncc_kept) :
        $kncc_notice_url = function ($action) {
          return wp_nonce_url(add_query_arg('kncc_guard_notice', $action), 'kncc_guard_notice');
        };
      ?>
      <div class="notice notice-info inline kncc-guard-notice" style="margin:16px 0 16px;padding:10px 14px;">
        <p>
          <?php
          if ($kncc_removed) {
            printf(
              /* translators: %s: comma-separated cookie names */
              esc_html__('Version 1.8.10 added cookies for services that are not on your site. They have been removed from the cookie list: %s. If your site does use some of these services, put the cookies back and delete the ones it does not use.', 'kansleri-cookie-consent'),
              '<strong>' . esc_html(implode(', ', $kncc_removed)) . '</strong>'
            );
          } else {
            printf(
              /* translators: %s: comma-separated cookie names */
              esc_html__('Version 1.8.10 may have added cookies for services that are not on your site: %s. The Google Tag Manager on your site can load any of them, so they were kept. Remove the ones your site does not use.', 'kansleri-cookie-consent'),
              '<strong>' . esc_html(implode(', ', $kncc_kept)) . '</strong>'
            );
          }
          ?>
        </p>
        <p>
          <?php if ($kncc_removed) : ?>
            <a class="button" href="<?php echo esc_url($kncc_notice_url('restore')); ?>"><?php esc_html_e('Put the cookies back', 'kansleri-cookie-consent'); ?></a>
          <?php endif; ?>
          <a class="button" href="<?php echo esc_url($kncc_notice_url('hide')); ?>"><?php esc_html_e('Hide this notice', 'kansleri-cookie-consent'); ?></a>
        </p>
      </div>
      <?php endif; ?>

      <div class="kncc-notice" id="kncc-notice" style="display:none;"></div>

      <?php
      switch ($active_tab) {
        case 'cookies':
          $this->render_cookies_tab($cookies, $cookies_synced_count);
          break;
        case 'policy':
          $this->render_policy_tab($settings, $pages);
          break;
        case 'integration':
          // Consent Mode is site-wide: show the base setting, not a per-language copy.
          $this->render_integration_tab(kncc_get_settings());
          break;
        case 'blocking':
          $this->render_blocking_tab();
          break;
        case 'statistics':
          $this->render_statistics_tab();
          break;
        case 'documentations':
          $this->render_documentations_tab();
          break;
        default:
          $this->render_general_tab($settings);
          break;
      }
      ?>
    </div>
    <?php
  }

  private function render_general_tab($settings) {
    // Placeholders (and auto-fill on save) use the locale of the language being edited: its translated defaults, or
    // English when the plugin has no translation for it — independent of the admin user's WP locale.
    $lang = kncc_get_current_language();
    $placeholder_source = kncc_get_default_settings_for_language($lang);
    $builtin_texts = kncc_get_builtin_texts_for_language($lang);
    ?>
    <?php $kncc_is_plugin_style = (($settings['text_style_mode'] ?? 'plugin') === 'plugin'); ?>
    <p class="kncc-preview-toolbar">
      <button type="button" id="kncc-open-preview" class="button button-secondary" <?php disabled(!$kncc_is_plugin_style); ?>>
        <?php esc_html_e('Open banner preview', 'kansleri-cookie-consent'); ?>
      </button>
      <span class="description kncc-preview-help-plugin"<?php echo $kncc_is_plugin_style ? '' : ' style="display:none;"'; ?>>
        <?php esc_html_e('Preview the banner with your current, unsaved settings.', 'kansleri-cookie-consent'); ?>
      </span>
      <span class="description kncc-preview-help-theme"<?php echo $kncc_is_plugin_style ? ' style="display:none;"' : ''; ?>>
        <?php esc_html_e('Live preview works with Plugin style only.', 'kansleri-cookie-consent'); ?>
      </span>
    </p>
    <form id="kncc-general-form" class="kncc-form">
      <input type="hidden" name="kncc_fields[]" value="show_floating_btn" />
      <table class="form-table">
        <tr>
          <th scope="row"><label for="kncc-banner-style"><?php esc_html_e('Banner style', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <select id="kncc-banner-style" name="banner_style">
              <option value="bar" <?php selected($settings['banner_style'], 'bar'); ?>><?php esc_html_e('Bottom bar', 'kansleri-cookie-consent'); ?></option>
              <option value="modal" <?php selected($settings['banner_style'], 'modal'); ?>><?php esc_html_e('Centered modal', 'kansleri-cookie-consent'); ?></option>
              <option value="corner" <?php selected($settings['banner_style'], 'corner'); ?>><?php esc_html_e('Corner popup', 'kansleri-cookie-consent'); ?></option>
            </select>
          </td>
        </tr>
        <?php $colors_visible = (($settings['text_style_mode'] ?? 'plugin') === 'plugin'); ?>
        <tr id="kncc-colors-row"<?php echo $colors_visible ? '' : ' style="display:none;"'; ?>>
          <th scope="row"><?php esc_html_e('Colors', 'kansleri-cookie-consent'); ?></th>
          <td>
            <?php
            $color_fields = array(
              'banner_bg_color'      => __('Banner background', 'kansleri-cookie-consent'),
              'heading_color'        => __('Heading text', 'kansleri-cookie-consent'),
              'body_color'           => __('Body text', 'kansleri-cookie-consent'),
              'primary_color'        => __('Primary button', 'kansleri-cookie-consent'),
              'primary_text_color'   => __('Primary button text', 'kansleri-cookie-consent'),
              'secondary_color'      => __('Secondary button', 'kansleri-cookie-consent'),
              'secondary_text_color' => __('Secondary button text', 'kansleri-cookie-consent'),
            );
            $defaults_for_fallback = kncc_get_default_settings();
            ?>
            <div class="kncc-color-grid">
              <?php foreach ($color_fields as $field => $label) :
                $value = isset($settings[$field]) && $settings[$field] !== '' ? $settings[$field] : $defaults_for_fallback[$field];
              ?>
                <div class="kncc-color-grid__item">
                  <span class="kncc-color-grid__label"><?php echo esc_html($label); ?></span>
                  <input type="text"
                         class="kncc-color-field"
                         name="<?php echo esc_attr($field); ?>"
                         value="<?php echo esc_attr($value); ?>"
                         data-default-color="<?php echo esc_attr($defaults_for_fallback[$field]); ?>" />
                </div>
              <?php endforeach; ?>
            </div>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-text-style-mode"><?php esc_html_e('Text and font style', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <select id="kncc-text-style-mode" name="text_style_mode">
              <option value="theme" <?php selected($settings['text_style_mode'] ?? 'plugin', 'theme'); ?>><?php esc_html_e('Theme style (inherit site typography)', 'kansleri-cookie-consent'); ?></option>
              <option value="plugin" <?php selected($settings['text_style_mode'] ?? 'plugin', 'plugin'); ?>><?php esc_html_e('Plugin style (strict font sizes/colors)', 'kansleri-cookie-consent'); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-heading"><?php esc_html_e('Heading text', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <input type="text" id="kncc-heading" name="heading_text" value="<?php echo esc_attr($settings['heading_text']); ?>" placeholder="<?php echo esc_attr($placeholder_source['heading_text']); ?>" class="regular-text" />
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-description"><?php esc_html_e('Description text', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <textarea id="kncc-description" name="description_text" rows="3" class="large-text" placeholder="<?php echo esc_attr($placeholder_source['description_text']); ?>"><?php echo esc_textarea($settings['description_text']); ?></textarea>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-accept"><?php esc_html_e('Accept button text', 'kansleri-cookie-consent'); ?></label></th>
          <td><input type="text" id="kncc-accept" name="accept_text" value="<?php echo esc_attr($settings['accept_text']); ?>" placeholder="<?php echo esc_attr($placeholder_source['accept_text']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-reject"><?php esc_html_e('Reject button text', 'kansleri-cookie-consent'); ?></label></th>
          <td><input type="text" id="kncc-reject" name="reject_text" value="<?php echo esc_attr($settings['reject_text']); ?>" placeholder="<?php echo esc_attr($placeholder_source['reject_text']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-customize"><?php esc_html_e('Customize button text', 'kansleri-cookie-consent'); ?></label></th>
          <td><input type="text" id="kncc-customize" name="customize_text" value="<?php echo esc_attr($settings['customize_text']); ?>" placeholder="<?php echo esc_attr($placeholder_source['customize_text']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-save"><?php esc_html_e('Save preferences button text', 'kansleri-cookie-consent'); ?></label></th>
          <td><input type="text" id="kncc-save" name="save_text" value="<?php echo esc_attr($settings['save_text']); ?>" placeholder="<?php echo esc_attr($placeholder_source['save_text']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-close"><?php esc_html_e('Close button label', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <input type="text" id="kncc-close" name="close_text" value="<?php echo esc_attr($settings['close_text'] ?? ''); ?>" placeholder="<?php echo esc_attr($placeholder_source['close_text']); ?>" class="regular-text" />
            <p class="description"><?php esc_html_e('Screen reader label of the × button.', 'kansleri-cookie-consent'); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-policy-link-text"><?php esc_html_e('Cookie policy link text', 'kansleri-cookie-consent'); ?></label></th>
          <td><input type="text" id="kncc-policy-link-text" name="policy_link_text" value="<?php echo esc_attr($settings['policy_link_text'] ?? ''); ?>" placeholder="<?php echo esc_attr($builtin_texts['policy_link_text']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-settings-link-text"><?php esc_html_e('Cookie settings label', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <input type="text" id="kncc-settings-link-text" name="settings_link_text" value="<?php echo esc_attr($settings['settings_link_text'] ?? ''); ?>" placeholder="<?php echo esc_attr($builtin_texts['settings_link_text']); ?>" class="regular-text" />
            <p class="description"><?php esc_html_e('Tooltip and screen reader label of the floating button, and the default text of the [kncc_cookie_settings] link.', 'kansleri-cookie-consent'); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-banner-label"><?php esc_html_e('Banner label for screen readers', 'kansleri-cookie-consent'); ?></label></th>
          <td><input type="text" id="kncc-banner-label" name="banner_label" value="<?php echo esc_attr($settings['banner_label'] ?? ''); ?>" placeholder="<?php echo esc_attr($builtin_texts['banner_label']); ?>" class="regular-text" /></td>
        </tr>
      </table>

      <details class="kncc-accordion" id="kncc-panel-categories">
        <summary><?php esc_html_e('Cookie categories', 'kansleri-cookie-consent'); ?></summary>
        <div class="kncc-accordion__body">
          <p class="description"><?php esc_html_e('Shown when visitors open Customize. Leave a field empty to use the built-in text.', 'kansleri-cookie-consent'); ?></p>
          <table class="form-table" role="presentation">
            <?php foreach (array_keys(kncc_get_categories()) as $key) : ?>
              <tr>
                <th scope="row"><label for="kncc-category-name-<?php echo esc_attr($key); ?>"><?php echo esc_html($builtin_texts['category_name_' . $key] . ': ' . __('Name', 'kansleri-cookie-consent')); ?></label></th>
                <td><input type="text" id="kncc-category-name-<?php echo esc_attr($key); ?>" name="category_name_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($settings['category_name_' . $key] ?? ''); ?>" placeholder="<?php echo esc_attr($builtin_texts['category_name_' . $key]); ?>" class="regular-text" /></td>
              </tr>
              <tr>
                <th scope="row"><label for="kncc-category-desc-<?php echo esc_attr($key); ?>"><?php echo esc_html($builtin_texts['category_name_' . $key] . ': ' . __('Description', 'kansleri-cookie-consent')); ?></label></th>
                <td><textarea id="kncc-category-desc-<?php echo esc_attr($key); ?>" name="category_desc_<?php echo esc_attr($key); ?>" rows="2" class="large-text" placeholder="<?php echo esc_attr($builtin_texts['category_desc_' . $key]); ?>"><?php echo esc_textarea($settings['category_desc_' . $key] ?? ''); ?></textarea></td>
              </tr>
              <?php if (isset($builtin_texts['empty_note_' . $key])) : ?>
                <tr>
                  <th scope="row"><label for="kncc-empty-note-<?php echo esc_attr($key); ?>"><?php echo esc_html($builtin_texts['category_name_' . $key] . ': ' . __('Text when no cookies are used', 'kansleri-cookie-consent')); ?></label></th>
                  <td><input type="text" id="kncc-empty-note-<?php echo esc_attr($key); ?>" name="empty_note_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($settings['empty_note_' . $key] ?? ''); ?>" placeholder="<?php echo esc_attr($builtin_texts['empty_note_' . $key]); ?>" class="regular-text" /></td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
            <tr>
              <th scope="row"><label for="kncc-empty-category-mode"><?php esc_html_e('Empty cookie categories', 'kansleri-cookie-consent'); ?></label></th>
              <td>
                <?php $empty_mode = (isset($settings['empty_category_mode']) && $settings['empty_category_mode'] === 'hide') ? 'hide' : 'disable'; ?>
                <select id="kncc-empty-category-mode" name="empty_category_mode">
                  <option value="disable" <?php selected($empty_mode, 'disable'); ?>><?php esc_html_e('Show but lock (disable) the switch', 'kansleri-cookie-consent'); ?></option>
                  <option value="hide" <?php selected($empty_mode, 'hide'); ?>><?php esc_html_e('Hide categories with no cookies', 'kansleri-cookie-consent'); ?></option>
                </select>
              </td>
            </tr>
          </table>
        </div>
      </details>

      <details class="kncc-accordion" id="kncc-panel-blocked">
        <summary><?php esc_html_e('Blocked content messages', 'kansleri-cookie-consent'); ?></summary>
        <div class="kncc-accordion__body">
          <table class="form-table" role="presentation">
            <?php
            $blocked_fields = array(
              array('iframe_placeholder_text', 'kncc-iframe-placeholder-text', __('YouTube & Vimeo message', 'kansleri-cookie-consent'), $placeholder_source['iframe_placeholder_text'], 'large-text'),
              array('iframe_accept_text', 'kncc-iframe-accept-text', __('YouTube & Vimeo accept button', 'kansleri-cookie-consent'), $placeholder_source['iframe_accept_text'], 'regular-text'),
              array('placeholder_text_analytics', 'kncc-placeholder-text-analytics', __('Analytics content message', 'kansleri-cookie-consent'), $builtin_texts['placeholder_text_analytics'], 'large-text'),
              array('placeholder_accept_analytics', 'kncc-placeholder-accept-analytics', __('Analytics content accept button', 'kansleri-cookie-consent'), $builtin_texts['placeholder_accept_analytics'], 'regular-text'),
              array('placeholder_text_preferences', 'kncc-placeholder-text-preferences', __('Preferences content message', 'kansleri-cookie-consent'), $builtin_texts['placeholder_text_preferences'], 'large-text'),
              array('placeholder_accept_preferences', 'kncc-placeholder-accept-preferences', __('Preferences content accept button', 'kansleri-cookie-consent'), $builtin_texts['placeholder_accept_preferences'], 'regular-text'),
            );
            foreach ($blocked_fields as $blocked_field) :
              list($field_name, $field_id, $field_label, $field_default, $field_class) = $blocked_field;
              ?>
              <tr>
                <th scope="row"><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($field_label); ?></label></th>
                <td><input type="text" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($settings[$field_name] ?? ''); ?>" placeholder="<?php echo esc_attr($field_default); ?>" class="<?php echo esc_attr($field_class); ?>" /></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </details>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><?php esc_html_e('Floating settings button', 'kansleri-cookie-consent'); ?></th>
          <td>
            <label>
              <input type="checkbox" name="show_floating_btn" value="1" <?php checked($settings['show_floating_btn']); ?> />
              <?php esc_html_e('Show a small floating button so visitors can re-open their cookie preferences', 'kansleri-cookie-consent'); ?>
            </label>
            <p class="kncc-shortcode-tip">
              <?php esc_html_e('Or add a link anywhere with the shortcode:', 'kansleri-cookie-consent'); ?>
              <code id="kncc-shortcode-code">[kncc_cookie_settings]</code>
              <button type="button"
                      class="button button-small kncc-copy-text-btn"
                      id="kncc-copy-shortcode-btn"
                      data-kncc-copy="[kncc_cookie_settings]"
                      data-kncc-copied-label="<?php esc_attr_e('Copied!', 'kansleri-cookie-consent'); ?>">
                <?php esc_html_e('Copy', 'kansleri-cookie-consent'); ?>
              </button>
            </p>
          </td>
        </tr>
      </table>
      <p class="submit">
        <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'kansleri-cookie-consent'); ?></button>
      </p>
    </form>

    <?php
    // Live preview — admin-only inline render of the banner.
    $preview_settings = array_merge($settings, array(
      'heading_text'     => $settings['heading_text'] !== '' ? $settings['heading_text'] : $placeholder_source['heading_text'],
      'description_text' => $settings['description_text'] !== '' ? $settings['description_text'] : $placeholder_source['description_text'],
      'accept_text'      => $settings['accept_text'] !== '' ? $settings['accept_text'] : $placeholder_source['accept_text'],
      'reject_text'      => $settings['reject_text'] !== '' ? $settings['reject_text'] : $placeholder_source['reject_text'],
      'customize_text'   => $settings['customize_text'] !== '' ? $settings['customize_text'] : $placeholder_source['customize_text'],
      'save_text'        => $settings['save_text'] !== '' ? $settings['save_text'] : $placeholder_source['save_text'],
      'close_text'       => (isset($settings['close_text']) && $settings['close_text'] !== '') ? $settings['close_text'] : $placeholder_source['close_text'],
    ));
    $preview_vars = KNCC_Consent::build_css_vars($preview_settings, '#kncc-preview-host');
    $text_style_mode = (isset($preview_settings['text_style_mode']) && $preview_settings['text_style_mode'] === 'theme') ? 'theme' : 'plugin';
    $banner_style    = isset($preview_settings['banner_style']) ? $preview_settings['banner_style'] : 'corner';
    $cats = kncc_get_categories();
    $preview_in_use = kncc_get_categories_in_use();
    $preview_empty_mode = (isset($preview_settings['empty_category_mode']) && $preview_settings['empty_category_mode'] === 'hide') ? 'hide' : 'disable';
    // Saved wording, or the built-in text of the language being edited.
    $preview_text = function ($key) use ($settings, $builtin_texts) {
      return (isset($settings[$key]) && is_string($settings[$key]) && trim($settings[$key]) !== '') ? $settings[$key] : $builtin_texts[$key];
    };
    ?>

    <style id="kncc-preview-vars"><?php echo $preview_vars; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values esc_attr'd inside build_css_vars. ?></style>
    <div id="kncc-preview-host" style="display:none;" aria-hidden="true">
      <div id="kncc-preview"
           class="kncc-banner kncc-banner--preview kncc-banner--<?php echo esc_attr($banner_style); ?> kncc-typography--<?php echo esc_attr($text_style_mode); ?>"
           role="dialog" aria-label="<?php esc_attr_e('Banner preview', 'kansleri-cookie-consent'); ?>">
        <div class="kncc-inner">
          <button type="button" class="kncc-banner-close" data-kncc-preview-close aria-label="<?php echo esc_attr($preview_settings['close_text']); ?>">&times;</button>
          <div class="kncc-main">
            <h2 class="kncc-heading kncc-preview-heading_text"><?php echo esc_html($preview_settings['heading_text']); ?></h2>
            <p class="kncc-desc kncc-preview-description_text"><?php echo esc_html($preview_settings['description_text']); ?></p>
            <div class="kncc-actions">
              <button type="button" class="kncc-btn kncc-btn--primary kncc-preview-accept_text"><?php echo esc_html($preview_settings['accept_text']); ?></button>
              <button type="button" class="kncc-btn kncc-btn--secondary kncc-preview-reject_text"><?php echo esc_html($preview_settings['reject_text']); ?></button>
              <button type="button" class="kncc-btn kncc-btn--link kncc-preview-customize_text"><?php echo esc_html($preview_settings['customize_text']); ?></button>
            </div>
          </div>
          <div class="kncc-details" style="display:none;">
            <?php foreach ($cats as $key => $data) :
              $is_necessary  = ($key === 'necessary');
              $has_cookies   = !empty($preview_in_use[$key]);
              $is_empty      = (!$is_necessary && !$has_cookies);
              $hidden_now    = ($is_empty && $preview_empty_mode === 'hide');
              $locked_now    = $is_necessary || ($is_empty && $preview_empty_mode === 'disable');
              $cat_classes   = 'kncc-category';
              if ($is_empty && $preview_empty_mode === 'disable') {
                $cat_classes .= ' kncc-category--empty';
              }
              $div_style = $hidden_now ? ' style="display:none;"' : '';
              ?>
              <div class="<?php echo esc_attr($cat_classes); ?>"
                   data-kncc-category="<?php echo esc_attr($key); ?>"
                   <?php if ($is_empty) echo 'data-kncc-empty="1"'; ?><?php echo $div_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>>
                <label class="kncc-category__label">
                  <input type="checkbox" class="kncc-category__check"
                    <?php
                    if ($is_necessary) {
                      echo 'checked disabled';
                    } elseif ($locked_now) {
                      echo 'disabled';
                    } elseif ($key === 'analytics') {
                      echo 'checked';
                    }
                    ?>
                  />
                  <span class="kncc-category__toggle"></span>
                  <span class="kncc-category__name kncc-preview-category_name_<?php echo esc_attr($key); ?>"><?php echo esc_html($preview_text('category_name_' . $key)); ?></span>
                </label>
                <p class="kncc-category__desc kncc-preview-category_desc_<?php echo esc_attr($key); ?>"><?php echo esc_html($preview_text('category_desc_' . $key)); ?></p>
                <?php if ($is_empty && isset($builtin_texts['empty_note_' . $key])) : ?>
                  <p class="kncc-category__empty-note kncc-preview-empty_note_<?php echo esc_attr($key); ?>"><?php echo esc_html($preview_text('empty_note_' . $key)); ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <div class="kncc-details-actions">
              <button type="button" class="kncc-btn kncc-btn--primary kncc-preview-save_text"><?php echo esc_html($preview_settings['save_text']); ?></button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
  }

  private function render_cookies_tab($cookies, $cookies_synced_count = 0) {
    $has_incomplete = false;
    foreach ($cookies as $c) {
      if (empty($c['description'])) {
        $has_incomplete = true;
        break;
      }
    }
    ?>
    <div class="kncc-cookies-tab">
      <?php if ($cookies_synced_count > 0) : ?>
        <div class="notice notice-success inline" style="margin:0 0 1rem;">
          <p>
            <?php
            printf(
              /* translators: %d: number of cookies added automatically */
              esc_html(_n(
                '%d cookie was automatically added based on tracking scripts detected on your site.',
                '%d cookies were automatically added based on tracking scripts detected on your site.',
                $cookies_synced_count,
                'kansleri-cookie-consent'
              )),
              (int) $cookies_synced_count
            );
            ?>
          </p>
        </div>
      <?php endif; ?>
      <div class="kncc-cookies-toolbar">
        <button type="button" class="button" id="kncc-scan-btn"><?php esc_html_e('Scan Cookies', 'kansleri-cookie-consent'); ?></button>
        <button type="button" class="button" id="kncc-add-cookie-btn"><?php esc_html_e('Add Cookie', 'kansleri-cookie-consent'); ?></button>
      </div>

      <div id="kncc-ai-helper" class="kncc-ai-helper" <?php if (!$has_incomplete) echo 'style="display:none;"'; ?>>
        <h4><?php esc_html_e('Need help describing these cookies?', 'kansleri-cookie-consent'); ?></h4>
        <ol class="kncc-ai-helper__steps">
          <li><?php esc_html_e('Click the button below to copy a ready-made AI prompt with your cookie data.', 'kansleri-cookie-consent'); ?></li>
          <li><?php esc_html_e('Paste the prompt into an AI tool (ChatGPT, Claude, Gemini, etc.).', 'kansleri-cookie-consent'); ?></li>
          <li><?php esc_html_e('The AI will return improved JSON with clear cookie descriptions.', 'kansleri-cookie-consent'); ?></li>
          <li><?php esc_html_e('Copy the AI response and paste it into the text box that appears below.', 'kansleri-cookie-consent'); ?></li>
          <li><?php esc_html_e('Click Import — cookies are saved automatically.', 'kansleri-cookie-consent'); ?></li>
        </ol>
        <button type="button" class="button button-primary kncc-button-with-icon" id="kncc-copy-ai-prompt-btn">
          <span class="dashicons dashicons-clipboard"></span>
          <?php esc_html_e('Copy AI Prompt to Clipboard', 'kansleri-cookie-consent'); ?>
        </button>

        <div id="kncc-import-json-area" class="kncc-import-json-area" style="display:none;">
          <label for="kncc-import-json-textarea"><strong><?php esc_html_e('Paste the AI response here:', 'kansleri-cookie-consent'); ?></strong></label>
          <textarea id="kncc-import-json-textarea" rows="8" class="large-text" placeholder="<?php esc_attr_e('[{"name":"_ga","category":"analytics", ...}]', 'kansleri-cookie-consent'); ?>"></textarea>
          <button type="button" class="button button-primary" id="kncc-import-json-btn">
            <?php esc_html_e('Import', 'kansleri-cookie-consent'); ?>
          </button>
        </div>
      </div>

      <div id="kncc-scan-results" style="display:none;" class="kncc-scan-results"></div>

      <form id="kncc-cookies-form">
        <table class="wp-list-table widefat striped kncc-cookie-table" id="kncc-cookie-table">
          <thead>
            <tr>
              <th><?php esc_html_e('Name', 'kansleri-cookie-consent'); ?></th>
              <th><?php esc_html_e('Category', 'kansleri-cookie-consent'); ?></th>
              <th><?php esc_html_e('Provider', 'kansleri-cookie-consent'); ?></th>
              <th><?php esc_html_e('Duration', 'kansleri-cookie-consent'); ?></th>
              <th><?php esc_html_e('Description', 'kansleri-cookie-consent'); ?></th>
              <th style="width:60px;"></th>
            </tr>
          </thead>
          <tbody id="kncc-cookie-tbody">
            <?php if (empty($cookies)) : ?>
              <tr class="kncc-no-cookies"><td colspan="6"><?php esc_html_e('No cookies registered yet.', 'kansleri-cookie-consent'); ?></td></tr>
            <?php else : ?>
              <?php foreach ($cookies as $i => $cookie) : ?>
                <tr data-index="<?php echo esc_attr( $i ); ?>">
                  <td><input type="text" name="cookies[<?php echo esc_attr( $i ); ?>][name]" value="<?php echo esc_attr($cookie['name']); ?>" class="regular-text" /></td>
                  <td>
                    <select name="cookies[<?php echo esc_attr( $i ); ?>][category]">
                      <option value="necessary" <?php selected($cookie['category'], 'necessary'); ?>><?php esc_html_e('Necessary', 'kansleri-cookie-consent'); ?></option>
                      <option value="analytics" <?php selected($cookie['category'], 'analytics'); ?>><?php esc_html_e('Analytics', 'kansleri-cookie-consent'); ?></option>
                      <option value="marketing" <?php selected($cookie['category'], 'marketing'); ?>><?php esc_html_e('Marketing', 'kansleri-cookie-consent'); ?></option>
                      <option value="preferences" <?php selected($cookie['category'], 'preferences'); ?>><?php esc_html_e('Preferences', 'kansleri-cookie-consent'); ?></option>
                    </select>
                  </td>
                  <td><input type="text" name="cookies[<?php echo esc_attr( $i ); ?>][provider]" value="<?php echo esc_attr($cookie['provider'] ?? ''); ?>" /></td>
                  <td><input type="text" name="cookies[<?php echo esc_attr( $i ); ?>][duration]" value="<?php echo esc_attr($cookie['duration'] ?? ''); ?>" style="width:100px;" /></td>
                  <td><input type="text" name="cookies[<?php echo esc_attr( $i ); ?>][description]" value="<?php echo esc_attr($cookie['description'] ?? ''); ?>" class="large-text" /></td>
                  <td><button type="button" class="button kncc-remove-cookie" title="<?php esc_attr_e('Remove', 'kansleri-cookie-consent'); ?>">&times;</button></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        <p class="submit">
          <button type="submit" class="button button-primary"><?php esc_html_e('Save Cookies', 'kansleri-cookie-consent'); ?></button>
        </p>
      </form>
    </div>
    <iframe id="kncc-scan-iframe" style="display:none;width:0;height:0;border:0;" sandbox="allow-same-origin allow-scripts"></iframe>
    <?php
  }

  private function render_policy_tab($settings, $pages) {
    // Prefer WordPress Privacy Policy page when none is selected yet.
    $assigned = KNCC_Policy_Page::maybe_assign_privacy_page();
    if ($assigned && empty($settings['policy_page_id'])) {
      $settings['policy_page_id'] = $assigned;
    }

    $policy_page_id = !empty($settings['policy_page_id']) ? (int) $settings['policy_page_id'] : 0;
    $wp_privacy_id = (int) get_option('wp_page_for_privacy_policy');

    if (!$policy_page_id) : ?>
      <div class="notice notice-warning inline" style="margin:1rem 0;">
        <p><span class="dashicons dashicons-warning" style="color:#d97706;"></span>
          <?php esc_html_e('No cookie policy page has been selected. Set a Privacy Policy page in Settings → Privacy, or choose a page below.', 'kansleri-cookie-consent'); ?>
        </p>
      </div>
    <?php endif; ?>

    <form id="kncc-policy-form" class="kncc-form">
      <table class="form-table">
        <tr>
          <th scope="row"><label for="kncc-policy-page"><?php esc_html_e('Cookie policy page', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <select id="kncc-policy-page" name="policy_page_id">
              <option value="0"><?php esc_html_e('— Select a page —', 'kansleri-cookie-consent'); ?></option>
              <?php foreach ($pages as $page) : ?>
                <option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected($policy_page_id, $page->ID); ?>>
                  <?php
                  $label = $page->post_title;
                  if ($wp_privacy_id && (int) $page->ID === $wp_privacy_id) {
                    /* translators: %s: page title */
                    $label = sprintf(__('%s (WordPress Privacy Policy)', 'kansleri-cookie-consent'), $page->post_title);
                  }
                  echo esc_html($label);
                  ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="description">
              <?php esc_html_e('Defaults automatically to the WordPress Privacy Policy page (Settings → Privacy). Add the shortcode [kncc_cookie_policy] to that page to show the cookie declaration.', 'kansleri-cookie-consent'); ?>
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kncc-policy-style-mode"><?php esc_html_e('Policy page style', 'kansleri-cookie-consent'); ?></label></th>
          <td>
            <select id="kncc-policy-style-mode" name="policy_style_mode">
              <option value="theme" <?php selected($settings['policy_style_mode'] ?? 'theme', 'theme'); ?>><?php esc_html_e('Theme style (inherit site typography)', 'kansleri-cookie-consent'); ?></option>
              <option value="plugin" <?php selected($settings['policy_style_mode'] ?? 'theme', 'plugin'); ?>><?php esc_html_e('Plugin style (strict font sizes/colors)', 'kansleri-cookie-consent'); ?></option>
            </select>
            <p class="description">
              <?php esc_html_e('Controls the styling of the [kncc_cookie_policy] cookie declaration table.', 'kansleri-cookie-consent'); ?>
              <?php esc_html_e('Theme style is the default so the policy page matches your site typography. Switch to Plugin style for strict plugin sizes and colors.', 'kansleri-cookie-consent'); ?>
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e('Policy page texts', 'kansleri-cookie-consent'); ?></th>
          <td>
            <p class="description"><?php esc_html_e('Texts of the [kncc_cookie_policy] cookie declaration. Leave a field empty to use the built-in text, which is translated into each language. Category names are edited on the General tab.', 'kansleri-cookie-consent'); ?></p>
          </td>
        </tr>
        <?php
            $builtin_texts = kncc_get_builtin_texts_for_language(kncc_get_current_language());
            $policy_text_groups = array(
              __('Category descriptions', 'kansleri-cookie-consent') => array(
                'policy_desc_necessary'   => array($builtin_texts['category_name_necessary'], true),
                'policy_desc_analytics'   => array($builtin_texts['category_name_analytics'], true),
                'policy_desc_marketing'   => array($builtin_texts['category_name_marketing'], true),
                'policy_desc_preferences' => array($builtin_texts['category_name_preferences'], true),
              ),
              __('Consent status', 'kansleri-cookie-consent') => array(
                'policy_status_heading' => array(__('Heading', 'kansleri-cookie-consent'), false),
                'status_no_choice'      => array(__('Before the visitor chooses', 'kansleri-cookie-consent'), false),
                'status_allowed'        => array(__('Allowed', 'kansleri-cookie-consent'), false),
                'status_denied'         => array(__('Denied', 'kansleri-cookie-consent'), false),
                'status_always_active'  => array(__('Always active', 'kansleri-cookie-consent'), false),
                'policy_status_note'    => array(__('Technical note (shown when WP Consent API is active)', 'kansleri-cookie-consent'), true),
              ),
              __('Buttons and messages', 'kansleri-cookie-consent') => array(
                'policy_edit_button' => array(__('Edit preferences button', 'kansleri-cookie-consent'), false),
                'policy_no_cookies'  => array(__('Message when no cookies are registered', 'kansleri-cookie-consent'), false),
              ),
              __('Table column headings', 'kansleri-cookie-consent') => array(
                'policy_col_cookie'   => array(__('Cookie', 'kansleri-cookie-consent'), false),
                'policy_col_provider' => array(__('Provider', 'kansleri-cookie-consent'), false),
                'policy_col_purpose'  => array(__('Purpose', 'kansleri-cookie-consent'), false),
                'policy_col_duration' => array(__('Duration', 'kansleri-cookie-consent'), false),
              ),
            );
            foreach ($policy_text_groups as $group_label => $fields) :
              foreach ($fields as $key => $field) :
                list($label, $multiline) = $field;
                $id = 'kncc-' . str_replace('_', '-', $key);
                ?>
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($group_label . ': ' . $label); ?></label></th>
          <td>
            <?php if ($multiline) : ?>
              <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" rows="2" class="large-text" placeholder="<?php echo esc_attr($builtin_texts[$key]); ?>"><?php echo esc_textarea($settings[$key] ?? ''); ?></textarea>
            <?php else : ?>
              <input type="text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($settings[$key] ?? ''); ?>" placeholder="<?php echo esc_attr($builtin_texts[$key]); ?>" class="regular-text" />
            <?php endif; ?>
          </td>
        </tr>
              <?php endforeach;
            endforeach; ?>
      </table>
      <p class="submit">
        <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'kansleri-cookie-consent'); ?></button>
      </p>
    </form>
    <?php
    if ($policy_page_id) :
      $link = get_permalink($policy_page_id);
      if ($link) :
    ?>
      <p><a href="<?php echo esc_url($link); ?>" target="_blank" class="button"><?php esc_html_e('Preview Policy Page', 'kansleri-cookie-consent'); ?></a></p>
    <?php
      endif;
    endif;
    ?>

    <div class="kncc-ai-helper" style="margin-top:1.5rem;">
      <h4><?php esc_html_e('Generate cookie policy page content with AI', 'kansleri-cookie-consent'); ?></h4>
      <p style="font-size:13px;color:#1e3a5f;margin:0 0 0.75rem;">
        <?php esc_html_e('Copy the prompt below and paste it into an AI tool. It will generate a complete cookie and privacy policy page in your site language. The cookie declaration will be handled automatically by the [kncc_cookie_policy] shortcode. Paste the generated HTML into your policy page.', 'kansleri-cookie-consent'); ?>
      </p>
      <button type="button" class="button button-primary kncc-button-with-icon" id="kncc-copy-policy-prompt-btn">
        <span class="dashicons dashicons-clipboard"></span>
        <?php esc_html_e('Copy AI Prompt for Policy Page', 'kansleri-cookie-consent'); ?>
      </button>
    </div>
    <?php
  }

  /**
   * Prints a help icon whose explanation opens in a tooltip on hover, tap or keyboard focus.
   * The paragraphs are plain text and are escaped here.
   */
  private function render_tooltip($id, $paragraphs) {
    ?>
    <span class="kncc-tooltip">
      <button type="button" class="kncc-tooltip__trigger" aria-label="<?php esc_attr_e('More information', 'kansleri-cookie-consent'); ?>" aria-describedby="<?php echo esc_attr($id); ?>">
        <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
      </button>
      <span class="kncc-tooltip__bubble" role="tooltip" id="<?php echo esc_attr($id); ?>">
        <?php foreach ($paragraphs as $paragraph) : ?>
          <span class="kncc-tooltip__para"><?php echo esc_html($paragraph); ?></span>
        <?php endforeach; ?>
      </span>
    </span>
    <?php
  }

  private function render_integration_tab($settings) {
    $sitekit_active = defined('GOOGLESITEKIT_VERSION');
    $sitekit_handles_consent = KNCC_Consent_Mode::site_kit_handles_consent();
    $wp_consent_api_active = KNCC_WP_Consent_API::is_active();
    ?>

    <?php if ($sitekit_active || $wp_consent_api_active) : ?>
    <h3><?php esc_html_e('Detected integrations', 'kansleri-cookie-consent'); ?></h3>
    <div class="kncc-integrations-status">
      <?php if ($sitekit_active) : ?>
      <div class="kncc-integration-badge kncc-integration-badge--active">
        <span class="dashicons dashicons-yes-alt"></span>
        <div>
          <strong>Google Site Kit</strong><br>
          <?php if ($sitekit_handles_consent) : ?>
            <span class="kncc-integration-detail"><?php esc_html_e('Active — Site Kit sets consent defaults for its own regions. This plugin sets a denied default for all other visitors and sends consent updates.', 'kansleri-cookie-consent'); ?></span>
          <?php else : ?>
            <span class="kncc-integration-detail"><?php esc_html_e('Active — Site Kit consent mode not enabled. This plugin handles all consent signals.', 'kansleri-cookie-consent'); ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($wp_consent_api_active) : ?>
      <div class="kncc-integration-badge kncc-integration-badge--active">
        <span class="dashicons dashicons-yes-alt"></span>
        <div>
          <strong>WP Consent API</strong><br>
          <span class="kncc-integration-detail"><?php esc_html_e('Active — consent status is shared with other plugins via the WP Consent API.', 'kansleri-cookie-consent'); ?></span>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <hr>
    <?php endif; ?>

    <form id="kncc-integration-form" class="kncc-form">
      <input type="hidden" name="kncc_fields[]" value="consent_mode" />
      <input type="hidden" name="kncc_fields[]" value="consent_mode_advanced" />
      <table class="form-table">
        <tr>
          <th scope="row"><?php esc_html_e('Google Consent Mode v2', 'kansleri-cookie-consent'); ?></th>
          <td>
            <div class="kncc-tooltip-row">
              <label>
                <input type="checkbox" name="consent_mode" value="1" <?php checked($settings['consent_mode'] || $sitekit_active); ?> <?php if ($sitekit_active) echo 'disabled'; ?> />
                <?php esc_html_e('Enable Consent Mode v2 (outputs consent defaults in &lt;head&gt; before GTM)', 'kansleri-cookie-consent'); ?>
                <?php if ($sitekit_active) : ?>
                  <input type="hidden" name="consent_mode" value="1" />
                <?php endif; ?>
              </label>
              <?php
              $consent_mode_help = array(
                __('Consent Mode tells Google tags what the visitor has allowed (for example analytics or ads). This plugin sends a denied-by-default signal before tags run, and sends an updated signal after the visitor chooses. It controls consent signals only: you still need to install GTM/GA scripts separately.', 'kansleri-cookie-consent'),
              );
              if ($sitekit_handles_consent) {
                $consent_mode_help[] = __('Site Kit sets consent defaults for its own regions. This plugin adds a denied default for all other visitors and sends each visitor\'s choice as a consent update.', 'kansleri-cookie-consent');
              }
              $this->render_tooltip('kncc-help-consent-mode', $consent_mode_help);
              ?>
            </div>
            <?php if ($sitekit_active) : ?>
              <p class="description" style="color:#065f46;">
                <span class="dashicons dashicons-lock" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
                <?php esc_html_e('Forced on because Google Site Kit is active. Consent Mode is required for Site Kit to receive consent signals.', 'kansleri-cookie-consent'); ?>
              </p>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e('Advanced Consent Mode', 'kansleri-cookie-consent'); ?></th>
          <td>
            <div class="kncc-tooltip-row">
              <label>
                <input type="checkbox" name="consent_mode_advanced" value="1" <?php checked(!empty($settings['consent_mode_advanced'])); ?> />
                <?php esc_html_e('Load Google tags before consent, with consent denied', 'kansleri-cookie-consent'); ?>
              </label>
              <?php
              $this->render_tooltip('kncc-help-consent-mode-advanced', array(
                __('Off (default, Basic Consent Mode): the Google tag is blocked until the visitor accepts analytics cookies, and Google Tag Manager until the visitor accepts analytics or marketing cookies, so Google receives nothing from visitors who have not consented.', 'kansleri-cookie-consent'),
                __('On (Advanced Consent Mode): the Google tag and Google Tag Manager load right away. Until the visitor accepts, they set no cookies and send Google only cookieless pings, which Google uses to model the missing data. Google Analytics and Google Ads expect this mode, and with Basic mode they can report your consent signals as not active.', 'kansleri-cookie-consent'),
                __('Only applies while Consent Mode v2 is enabled. Other tracking scripts on the site stay blocked until consent, also those that Google Tag Manager adds (for example a Meta Pixel or LinkedIn in a Custom HTML tag): each waits for its own category, with no changes needed in the container.', 'kansleri-cookie-consent'),
              ));
              ?>
            </div>
          </td>
        </tr>
      </table>

      <?php if (!$sitekit_active) : ?>
      <div style="margin-top:1.25rem;padding:16px 18px;background:#f0f6fc;border-left:4px solid #2563eb;border-radius:2px;">
        <h3 style="margin:0 0 6px;font-size:15px;">
          <span class="dashicons dashicons-lightbulb" style="color:#2563eb;font-size:18px;width:18px;height:18px;vertical-align:text-bottom;"></span>
          <?php esc_html_e('Need Google Analytics 4 or Google Tag Manager?', 'kansleri-cookie-consent'); ?>
        </h3>
        <p style="margin:0 0 8px;">
          <?php esc_html_e('We recommend installing Google Site Kit — Google\'s official WordPress plugin. It sets up GA4 and GTM in a few clicks, and works seamlessly with Kansleri Cookie Consent: Consent Mode v2 signals are handled automatically, with no double-loading or conflicts.', 'kansleri-cookie-consent'); ?>
        </p>
        <p style="margin:0;">
          <a href="https://wordpress.org/plugins/google-site-kit/" target="_blank" rel="noopener" class="button button-secondary kncc-button-with-icon">
            <span class="dashicons dashicons-external"></span>
            <?php esc_html_e('Install Google Site Kit', 'kansleri-cookie-consent'); ?>
          </a>
        </p>
      </div>
      <?php endif; ?>

      <table style="display:none;">
      </table>

      <?php if (KNCC_Consent_Mode::is_enabled($settings)) : ?>
      <?php $preview_advanced = !empty($settings['consent_mode_advanced']); ?>
      <div class="kncc-code-preview">
        <h3><?php esc_html_e('Consent Mode defaults (auto-injected in &lt;head&gt;)', 'kansleri-cookie-consent'); ?></h3>
        <?php // The defaults are the same in both modes; what changes is whether Google tags wait for consent. admin.js switches the comment with the checkbox. ?>
        <pre><code>gtag('consent', 'default', {
  ad_storage: 'denied',
  ad_user_data: 'denied',
  ad_personalization: 'denied',
  analytics_storage: 'denied',
  functionality_storage: 'denied',
  personalization_storage: 'denied',
  security_storage: 'granted',
  wait_for_update: 500,
});

<span class="kncc-code-preview__comment" data-kncc-preview="basic"<?php if ($preview_advanced) echo ' hidden'; ?>>// <?php esc_html_e('Basic Consent Mode: the Google tag waits until the visitor accepts analytics cookies, and Google Tag Manager until the visitor accepts analytics or marketing cookies.', 'kansleri-cookie-consent'); ?></span><span class="kncc-code-preview__comment" data-kncc-preview="advanced"<?php if (!$preview_advanced) echo ' hidden'; ?>>// <?php esc_html_e('Advanced Consent Mode: the Google tag and Google Tag Manager load right away with these defaults, and send only cookieless pings until the visitor accepts.', 'kansleri-cookie-consent'); ?></span></code></pre>
      </div>
      <?php endif; ?>

      <p class="submit">
        <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'kansleri-cookie-consent'); ?></button>
      </p>
    </form>
    <?php
  }

  private function render_documentations_tab() {
    ?>
    <div class="kncc-docs">
      <h2><?php esc_html_e('Installation guide', 'kansleri-cookie-consent'); ?></h2>
      <ol>
        <li><?php esc_html_e('Activate the plugin and open Settings > Cookie Consent.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('Configure banner texts, style, and button labels in the General tab.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('Open the Integration tab and enable Google Consent Mode v2 if you use GTM/Google tags.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('Open the Cookies tab, run Scan Cookies, then review and categorize unknown cookies.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('If unknown cookies are found, an AI helper appears: copy the prompt, paste it into an AI tool, paste the response back into the text box, click Import, then Save Cookies.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('Confirm the Cookie Policy page in the Policy Page tab — it defaults to the WordPress Privacy Policy page.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('Use the AI prompt on the Policy Page tab to generate the full cookie policy page content. Paste the generated HTML into your policy page.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('Review the Script Blocking tab to see which scripts are auto-blocked.', 'kansleri-cookie-consent'); ?></li>
      </ol>

      <h2><?php esc_html_e('What each option means', 'kansleri-cookie-consent'); ?></h2>

      <h3><?php esc_html_e('General tab', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('Banner style', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Choose visual placement of the banner (bar, modal, corner).', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Primary color', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Controls the main accent color used by buttons and links.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Text and font style', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Theme style inherits site typography. Plugin style uses strict sizes/colors for consistent readability.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Heading/Description/Button texts', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Customize visible wording in the consent banner.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Floating settings button', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Lets visitors re-open cookie settings after they close the banner.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h3><?php esc_html_e('Cookies tab', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('Scan Cookies', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Scans browser cookies and infers expected cookies from tracking scripts on your site (even when blocked until consent). Known cookies are auto-mapped to categories.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('AI Helper', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Appears after scanning when unknown cookies are found. Copies a ready prompt to paste into an AI tool. After the AI responds, paste the JSON back into the text box and click Import.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Save Cookies', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Persists current cookie table values to be used in policy output and consent details.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Possibly removed', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('After scanning, cookies whose tracking scripts are no longer found on the homepage are listed under "Possibly removed". Review the list and use Remove selected cookies to delete entries you no longer need — nothing is removed automatically.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h3><?php esc_html_e('Policy Page tab', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('Cookie policy page', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Selects which page is used for cookie policy content generated by shortcode.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Current consent status', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('The [kncc_cookie_policy] shortcode shows each category as allowed, denied, or not yet chosen above the Edit cookie preferences button. When WP Consent API is active, a note explains the technical wp_consent_* cookie names used by WordPress plugins.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('AI Prompt for Policy Page', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Generates a prompt you can paste into an AI tool to create a full privacy and cookie policy. The generated text will include the [kncc_cookie_policy] shortcode for the cookie declaration section.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Multilingual support', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('If Polylang or WPML is active, each language can have its own translated policy page. If a translation is missing for some language, the default-language policy page is used as a fallback.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h3><?php esc_html_e('Integration tab', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('Google Consent Mode v2', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('A Google framework for privacy-safe tag behavior. Before consent, storage signals are denied; after user choice, signals are updated (for example analytics_storage=granted). This helps GTM/Google tags respect user consent state.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Advanced Consent Mode', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Off by default. When on, the Google tag and Google Tag Manager load before consent instead of being blocked. Until the visitor accepts, they set no cookies and send Google only cookieless pings.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('GTM Container ID', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Your GTM container identifier (for example GTM-N6828NL). It identifies which tag container your site uses. This field is informational in this plugin and does not inject GTM scripts.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('WP Consent API sync', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('When the WP Consent API plugin is active, Kansleri automatically syncs your banner choices to wp_consent_functional, wp_consent_statistics, wp_consent_marketing, and wp_consent_preferences. This lets consent-aware plugins such as Google Site Kit read the visitor\'s choice. Banner labels (Necessary, Analytics) map to these technical cookie names.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h3><?php esc_html_e('Script Blocking tab', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('Recognized tracking tools', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Tools like Google Analytics are blocked automatically when added through WordPress plugins (e.g. Google Site Kit).', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Custom Code blocking', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Stops tracking pasted into Bricks, Elementor, or similar Custom Code fields until the visitor gives consent.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('YouTube & Vimeo iframe blocking', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Video embeds from YouTube and Vimeo are automatically blocked until the visitor accepts marketing cookies. A message with an accept button is shown instead. When the visitor has already consented, videos load normally.', 'kansleri-cookie-consent'); ?> <?php esc_html_e('You can turn this off on the Script Blocking tab.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Reviews widgets', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('The Trustmary reviews widget waits for analytics consent, because it stores a visitor ID in the browser. A message with an accept button is shown in its place. To load it right away, add widget.trustmary.com under Block by address with the category “Necessary (never blocked)”.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Block by address', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Blocks any other script by part of its address until the visitor accepts the category you choose, for example a widget the plugin does not recognize. Turn on Show message for scripts that show content on the page.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h3><?php esc_html_e('Statistics tab', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('Consent statistics', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Shows how many visitors accepted, rejected, or customized cookies over 7, 30, or 90 day periods. Data is aggregated and anonymous — no personal data is stored.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Consent Log', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Individual anonymized consent records for GDPR audit compliance. Records contain a hashed visitor identifier, the consent choice, accepted categories, and the policy version. Records are automatically purged after 13 months.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Export CSV', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Downloads all consent log records as a CSV file for auditing.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Purge All Records', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Permanently deletes all consent log records and aggregate statistics.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h3><?php esc_html_e('Multilingual support (WPML & Polylang)', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('Per-language banner texts', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('When WPML or Polylang is active, switch to a secondary language in the admin bar and edit General tab settings (saved per language). With WPML String Translation, you can also translate the [kncc_settings]* admin text strings — those are used when no per-language override is saved. Otherwise each language uses its own plugin defaults (English if unsupported). Texts are never copied blindly from the default language.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Per-language cookie descriptions', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Cookie names, categories, providers, and durations are shared across all languages. Only the description field can be translated per language. Switch language and edit descriptions in the Cookies tab.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('Per-language settings', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('Banner style, colors, and policy page can be set per language. Non-text settings fall back to the default language; banner texts use per-language overrides, WPML String Translation, or each language\'s own defaults.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><?php esc_html_e('AI prompts', 'kansleri-cookie-consent'); ?>:</strong> <?php esc_html_e('AI prompts for cookie descriptions and policy pages are automatically generated in the language matching the current admin locale.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h3><?php esc_html_e('Shortcodes', 'kansleri-cookie-consent'); ?></h3>
      <ul>
        <li><strong><code>[kncc_cookie_policy]</code>:</strong> <?php esc_html_e('Displays the full cookie declaration table on your policy page.', 'kansleri-cookie-consent'); ?></li>
        <li><strong><code>[kncc_cookie_settings]</code>:</strong> <?php esc_html_e('Renders a link that opens the cookie settings dialog. Use in footers, menus, or widgets. Customize the link text with the text attribute, e.g. [kncc_cookie_settings text="Manage cookies"].', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h2><?php esc_html_e('Custom CSS', 'kansleri-cookie-consent'); ?></h2>
      <p><?php
      printf(
        /* translators: %s: Customizer path */
        esc_html__('Need to fine-tune the look beyond the General tab? Add CSS to %s, your child theme, or a Code Snippets plugin.', 'kansleri-cookie-consent'),
        '<strong>' . esc_html__('Appearance → Customize → Additional CSS', 'kansleri-cookie-consent') . '</strong>'
      );
      ?></p>
      <p><?php esc_html_e('Copy the template below, uncomment the rules you want to change, save. Every selector the plugin uses is included.', 'kansleri-cookie-consent'); ?></p>
      <p id="kncc-css-template-feedback" style="display:none;margin:8px 0;color:#065f46;font-weight:600;">
        <span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom;margin-right:4px;"></span>
        <?php esc_html_e('CSS template copied to clipboard.', 'kansleri-cookie-consent'); ?>
      </p>
      <p>
        <button type="button" class="button button-primary kncc-button-with-icon" id="kncc-copy-css-template-btn">
          <span class="dashicons dashicons-clipboard"></span>
          <?php esc_html_e('Copy CSS template', 'kansleri-cookie-consent'); ?>
        </button>
      </p>
<pre style="background:#f6f7f7;padding:12px;border-radius:4px;overflow-x:auto;white-space:pre;margin-top:8px;"><code id="kncc-css-template" style="background:transparent;padding:0;display:block;"><?php echo esc_html(
"/* Quick palette — change all colors in one place. The General tab's Colors
   row writes to these same variables when Plugin style is active. */
:root {
  --kncc-banner-bg:        #fff;       /* banner background */
  --kncc-heading:          #1f2937;    /* heading text */
  --kncc-body:             #4b5563;    /* body / Close / off-toggle */
  --kncc-primary:          #3D4872;    /* Accept + on-state toggle */
  --kncc-primary-text:     #fff;       /* text on Accept + on-state knob */
  --kncc-secondary:        #f3f4f6;    /* Reject background */
  --kncc-secondary-text:   #374151;    /* text on Reject */
}

/* Banner */
.kncc-banner            { /* whole banner, fixed position, z-index 999999 */ }
.kncc-banner--bar       { /* bottom-bar variant */ }
.kncc-banner--modal     { /* centered-modal variant */ }
.kncc-banner--corner    { /* corner-popup variant */ }
.kncc-overlay           { /* dark backdrop behind modal */ }
.kncc-inner             { /* inner card on modal/corner */ }
.kncc-heading           { /* \"We use cookies\" heading */ }
.kncc-desc              { /* description paragraph */ }
.kncc-policy-link       { /* link to your cookie policy page */ }
.kncc-actions           { /* button row container */ }
.kncc-details           { /* collapsible category panel */ }
.kncc-details-actions   { /* \"Save preferences\" button container */ }

/* Buttons */
.kncc-btn--primary                    { /* Accept */ }
.kncc-btn--secondary                  { /* Reject */ }
.kncc-btn--link                       { /* Customize */ }
.kncc-banner-close                    { /* × dismiss in top-right corner */ }

/* Category toggles */
.kncc-category__label                                   { /* clickable row */ }
.kncc-category__toggle                                  { /* off-state track */ }
.kncc-category__toggle::after                           { /* off-state knob */ }
.kncc-category__check:checked + .kncc-category__toggle  { /* on-state track */ }
.kncc-category__name                                    { /* category name */ }
.kncc-category__desc                                    { /* category description */ }

/* Floating button (shown after consent) */
.kncc-floating-btn      { /* small cookie icon, fixed position */ }

/* Policy page (rendered by [kncc_cookie_policy]) */
.kncc-policy            { /* root wrapper */ }
.kncc-policy__section   { /* one category block */ }
.kncc-policy__table     { /* cookies table (responsive on mobile) */ }
.kncc-edit-consent-btn  { /* \"Edit cookie preferences\" button */ }

/* Blocked iframes (YouTube / Vimeo) */
.kncc-iframe-placeholder           { /* wrapper that replaces the iframe */ }
.kncc-iframe-placeholder__overlay  { /* text + accept button over thumbnail */ }
.kncc-iframe-accept                { /* \"Accept marketing cookies\" button */ }

/* Examples — uncomment to use */
/*
.kncc-btn--primary {
  border-radius: 999px;
  padding: 0.7rem 2rem;
}
*/
/*
@media (max-width: 600px) {
  .kncc-floating-btn { display: none; }
}
*/"
); ?></code></pre>

      <p><strong><?php esc_html_e('Tips:', 'kansleri-cookie-consent'); ?></strong></p>
      <ul>
        <li><?php esc_html_e('If a rule does not apply, prepend .kncc-banner or add !important — your CSS file loads after the plugin\'s, but theme styles can occasionally beat it on specificity.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('When General → Text and font style is set to Plugin style, typography rules use !important. Switch to Theme style for easier overrides.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('Banner z-index is 999999 and the floating button is 999998 — raise them if your theme renders overlays on top of the banner.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <h2><?php esc_html_e('Troubleshooting', 'kansleri-cookie-consent'); ?></h2>
      <ul>
        <li><?php esc_html_e('If GTM Preview does not show consent updates, verify Consent Mode v2 is enabled and test in a fresh browser session without existing consent cookie.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('If banner typography looks odd with your theme, switch General > Text and font style to Plugin style.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('If tracking still runs before consent, make sure Custom Code blocking is checked on the Script Blocking tab.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('If the statistics are empty, make sure visitors have interacted with the banner. Data starts recording after the first consent choice.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('If the policy page warning shows missing translations, create the translated page in each language and add the [kncc_cookie_policy] shortcode.', 'kansleri-cookie-consent'); ?></li>
        <li><?php esc_html_e('If wp_consent_statistics stays deny after Accept all, clear site cookies or test in a private window, hard-refresh (Ctrl+Shift+R), and confirm the WP Consent API plugin is active.', 'kansleri-cookie-consent'); ?></li>
      </ul>

      <hr style="margin: 2em 0;" />
      <p><?php esc_html_e('I made this plugin for free because I got frustrated by the existing cookie consent plugins — too much bloat and useless features. If you like it and want to buy me a coffee, you can do it here:', 'kansleri-cookie-consent'); ?> <a href="https://ko-fi.com/tapiok" target="_blank" rel="noopener noreferrer">ko-fi.com/tapiok</a></p>
    </div>
    <?php
  }

  private function render_statistics_tab() {
    $period = isset($_GET['period']) ? absint($_GET['period']) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigational period parameter
    if (!in_array($period, array(7, 30, 90), true)) {
      $period = 30;
    }

    $totals = KNCC_Stats::get_totals($period);
    $accept_pct = $totals['total'] ? round($totals['accept'] / $totals['total'] * 100) : 0;
    $reject_pct = $totals['total'] ? round($totals['reject'] / $totals['total'] * 100) : 0;
    $custom_pct = $totals['total'] ? round($totals['custom'] / $totals['total'] * 100) : 0;

    ?>
    <div class="kncc-stats">
      <div class="kncc-stats__period">
        <?php
        $base = '?page=kansleri-cookie-consent&tab=statistics&period=';
        foreach (array(7, 30, 90) as $p) :
        ?>
          <a href="<?php echo esc_url(admin_url('options-general.php' . $base . $p)); ?>"
             class="button <?php echo $period === $p ? 'button-primary' : ''; ?>">
            <?php
            printf(
              /* translators: %d: number of days */
              esc_html__('%d days', 'kansleri-cookie-consent'),
              (int) $p
            );
            ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="kncc-stats__cards">
        <div class="kncc-stats__card kncc-stats__card--total">
          <div class="kncc-stats__card-value"><?php echo esc_html($totals['total']); ?></div>
          <div class="kncc-stats__card-label"><?php esc_html_e('Total responses', 'kansleri-cookie-consent'); ?></div>
        </div>
        <div class="kncc-stats__card kncc-stats__card--accept">
          <div class="kncc-stats__card-value"><?php echo esc_html($totals['accept']); ?></div>
          <div class="kncc-stats__card-label"><?php esc_html_e('Accepted all', 'kansleri-cookie-consent'); ?> <span class="kncc-stats__pct"><?php echo esc_html($accept_pct); ?>%</span></div>
        </div>
        <div class="kncc-stats__card kncc-stats__card--reject">
          <div class="kncc-stats__card-value"><?php echo esc_html($totals['reject']); ?></div>
          <div class="kncc-stats__card-label"><?php esc_html_e('Rejected all', 'kansleri-cookie-consent'); ?> <span class="kncc-stats__pct"><?php echo esc_html($reject_pct); ?>%</span></div>
        </div>
        <div class="kncc-stats__card kncc-stats__card--custom">
          <div class="kncc-stats__card-value"><?php echo esc_html($totals['custom']); ?></div>
          <div class="kncc-stats__card-label"><?php esc_html_e('Custom selection', 'kansleri-cookie-consent'); ?> <span class="kncc-stats__pct"><?php echo esc_html($custom_pct); ?>%</span></div>
        </div>
      </div>

      <?php if ($totals['total'] > 0) : ?>
      <div class="kncc-stats__bar">
        <?php if ($accept_pct > 0) : ?>
          <div class="kncc-stats__bar-seg kncc-stats__bar-seg--accept" style="width:<?php echo esc_attr( (int) $accept_pct ); ?>%;" title="<?php esc_attr_e('Accepted all', 'kansleri-cookie-consent'); ?>: <?php echo esc_attr( (int) $accept_pct ); ?>%"><?php echo esc_html( (int) $accept_pct ); ?>%</div>
        <?php endif; ?>
        <?php if ($custom_pct > 0) : ?>
          <div class="kncc-stats__bar-seg kncc-stats__bar-seg--custom" style="width:<?php echo esc_attr( (int) $custom_pct ); ?>%;" title="<?php esc_attr_e('Custom selection', 'kansleri-cookie-consent'); ?>: <?php echo esc_attr( (int) $custom_pct ); ?>%"><?php echo esc_html( (int) $custom_pct ); ?>%</div>
        <?php endif; ?>
        <?php if ($reject_pct > 0) : ?>
          <div class="kncc-stats__bar-seg kncc-stats__bar-seg--reject" style="width:<?php echo esc_attr( (int) $reject_pct ); ?>%;" title="<?php esc_attr_e('Rejected all', 'kansleri-cookie-consent'); ?>: <?php echo esc_attr( (int) $reject_pct ); ?>%"><?php echo esc_html( (int) $reject_pct ); ?>%</div>
        <?php endif; ?>
      </div>
      <div class="kncc-stats__legend">
        <span class="kncc-stats__legend-item"><span class="kncc-stats__dot kncc-stats__dot--accept"></span> <?php esc_html_e('Accepted all', 'kansleri-cookie-consent'); ?></span>
        <span class="kncc-stats__legend-item"><span class="kncc-stats__dot kncc-stats__dot--custom"></span> <?php esc_html_e('Custom selection', 'kansleri-cookie-consent'); ?></span>
        <span class="kncc-stats__legend-item"><span class="kncc-stats__dot kncc-stats__dot--reject"></span> <?php esc_html_e('Rejected all', 'kansleri-cookie-consent'); ?></span>
      </div>
      <?php endif; ?>

      <?php if (!$totals['total']) : ?>
        <p class="kncc-stats__empty"><?php esc_html_e('No consent data recorded yet. Statistics will appear after visitors start interacting with the cookie banner.', 'kansleri-cookie-consent'); ?></p>
      <?php endif; ?>
    </div>

    <?php $this->render_consent_log_section(); ?>
    <?php
  }

  private function render_status_label() {
    $cron_error = get_option('kncc_cron_error');
    if ($cron_error) {
      ?>
      <div class="kncc-status-label kncc-status-label--error">
        <span class="dashicons dashicons-warning"></span>
        <?php
        printf(
          /* translators: %s: error message */
          esc_html__('Cron error: %s', 'kansleri-cookie-consent'),
          esc_html($cron_error)
        );
        ?>
      </div>
      <?php
      return;
    }

    $cookies = kncc_get_cookies();
    $incomplete = get_option('kncc_incomplete_cookies');
    $all_good = !empty($cookies) && (empty($incomplete) || !is_array($incomplete));

    if ($all_good) {
      $next_run = wp_next_scheduled('kncc_daily_cookie_check');
      ?>
      <div class="kncc-status-label kncc-status-label--good">
        <span class="dashicons dashicons-yes-alt"></span>
        <?php esc_html_e('All systems are good!', 'kansleri-cookie-consent'); ?>
        <span class="kncc-status-label__sub">
          &mdash; <?php esc_html_e('The plugin re-checks cookies every day via cron.', 'kansleri-cookie-consent'); ?>
          <?php if ($next_run) : ?>
            <?php
            printf(
              /* translators: %s: date/time of next cron run */
              esc_html__('Next check: %s', 'kansleri-cookie-consent'),
              esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run))
            );
            ?>
          <?php endif; ?>
        </span>
      </div>
      <?php
    }
  }

  private function render_blocking_tab() {
    $settings = kncc_get_settings();
    $block_html = !isset($settings['block_html_scripts']) || !empty($settings['block_html_scripts']);
    $block_iframes = !isset($settings['block_iframes']) || !empty($settings['block_iframes']);
    ?>
    <div class="kncc-blocking-tab">
      <?php if (KNCC_Consent_Mode::is_advanced($settings)) : ?>
        <p class="description kncc-blocking-note">
          <span class="dashicons dashicons-info"></span>
          <?php esc_html_e('Advanced Consent Mode is on (Integration tab): the Google tag and Google Tag Manager load before consent and follow the Consent Mode signals instead of being blocked.', 'kansleri-cookie-consent'); ?>
        </p>
      <?php endif; ?>

      <form id="kncc-blocking-form" class="kncc-blocking-form">
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('Custom Code blocking', 'kansleri-cookie-consent'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="block_html_scripts" value="1" <?php checked($block_html); ?> />
                <?php esc_html_e('Also block tracking added in Bricks, Elementor, or other Custom Code fields', 'kansleri-cookie-consent'); ?>
              </label>
              <p class="description"><?php esc_html_e('Recommended. Turn off only if a cache or speed plugin breaks the site.', 'kansleri-cookie-consent'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('YouTube & Vimeo iframe blocking', 'kansleri-cookie-consent'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="block_iframes" value="1" <?php checked($block_iframes); ?> />
                <?php esc_html_e('Block YouTube and Vimeo videos until the visitor accepts marketing cookies', 'kansleri-cookie-consent'); ?>
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <div class="kncc-tooltip-row kncc-tooltip-row--heading">
                <?php esc_html_e('Block by address', 'kansleri-cookie-consent'); ?>
                <?php
                $this->render_tooltip('kncc-help-block-by-address', array(
                  __('Holds any other script back until the visitor accepts its category. Use it for widgets and tools the plugin does not recognize.', 'kansleri-cookie-consent'),
                  __('Necessary (never blocked) lets an address load right away, even one the plugin would block.', 'kansleri-cookie-consent'),
                  __('Show message: where the blocked script would show content (reviews, booking, a map), show a message with an accept button instead of an empty gap. Not needed for tracking scripts.', 'kansleri-cookie-consent'),
                ));
                ?>
              </div>
            </th>
            <td>
              <?php $this->render_url_rules_table(KNCC_Script_Blocker::get_url_rules()); ?>
              <p class="description kncc-html-rules-note"<?php echo $block_html ? ' hidden' : ''; ?>><?php esc_html_e('Scripts in page HTML are matched only while Custom Code blocking is on.', 'kansleri-cookie-consent'); ?></p>
            </td>
          </tr>
        </table>
        <p class="submit">
          <button type="submit" class="button button-primary"><?php esc_html_e('Save blocking settings', 'kansleri-cookie-consent'); ?></button>
        </p>
      </form>

      <h3><?php esc_html_e('Recognized tracking tools', 'kansleri-cookie-consent'); ?></h3>
      <p class="description"><?php esc_html_e('Blocked automatically, no setup needed.', 'kansleri-cookie-consent'); ?></p>
      <table class="wp-list-table widefat striped kncc-recognized-tools">
        <thead>
          <tr>
            <th><?php esc_html_e('Script Handle', 'kansleri-cookie-consent'); ?></th>
            <th><?php esc_html_e('Category', 'kansleri-cookie-consent'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php $categories = kncc_get_categories(); ?>
          <?php foreach (KNCC_Script_Blocker::detect_registered_scripts() as $handle => $info) : ?>
            <tr>
              <td><code><?php echo esc_html($handle); ?></code></td>
              <td class="kncc-scan-cat kncc-scan-cat--<?php echo esc_attr($info['category']); ?>"><?php echo esc_html(isset($categories[$info['category']]) ? $categories[$info['category']]['label'] : ucfirst($info['category'])); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
  }

  /**
   * "Block by address" rules table on the Script Blocking tab. The <template> row is cloned
   * by admin.js for "Add address". The columns have fixed widths (admin.css), so the table keeps
   * its size whatever the rows hold.
   */
  private function render_url_rules_table($rules) {
    ?>
    <table class="widefat striped kncc-url-rules">
      <thead>
        <tr>
          <th class="kncc-col-address"><?php esc_html_e('Address contains', 'kansleri-cookie-consent'); ?></th>
          <th class="kncc-col-category"><?php esc_html_e('Category', 'kansleri-cookie-consent'); ?></th>
          <th class="kncc-col-message"><?php esc_html_e('Show message', 'kansleri-cookie-consent'); ?></th>
          <th class="kncc-col-remove"><span class="screen-reader-text"><?php esc_html_e('Remove', 'kansleri-cookie-consent'); ?></span></th>
        </tr>
      </thead>
      <tbody id="kncc-url-rules-body">
        <?php foreach ($rules as $rule) : ?>
          <?php $this->render_url_rule_row($rule); ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    <template id="kncc-url-rule-template">
      <?php $this->render_url_rule_row(array('pattern' => '', 'category' => 'analytics', 'placeholder' => false)); ?>
    </template>
    <p><button type="button" class="button" id="kncc-add-url-rule"><?php esc_html_e('Add address', 'kansleri-cookie-consent'); ?></button></p>
    <?php
  }

  private function render_url_rule_row($rule) {
    $categories = kncc_get_categories();
    $options = array(
      'analytics'   => $categories['analytics']['label'],
      'marketing'   => $categories['marketing']['label'],
      'preferences' => $categories['preferences']['label'],
      'necessary'   => __('Necessary (never blocked)', 'kansleri-cookie-consent'),
    );
    ?>
    <tr class="kncc-url-rule">
      <td class="kncc-col-address">
        <input type="text" class="kncc-url-rule__pattern" value="<?php echo esc_attr($rule['pattern']); ?>" placeholder="widget.example.com" aria-label="<?php esc_attr_e('Address contains', 'kansleri-cookie-consent'); ?>" />
      </td>
      <td class="kncc-col-category">
        <select class="kncc-url-rule__category" aria-label="<?php esc_attr_e('Category', 'kansleri-cookie-consent'); ?>">
          <?php foreach ($options as $value => $label) : ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($rule['category'], $value); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td class="kncc-col-message">
        <label>
          <input type="checkbox" class="kncc-url-rule__placeholder" value="1" <?php checked(!empty($rule['placeholder'])); ?> />
          <span class="kncc-url-rule__message-label"><?php esc_html_e('Show message', 'kansleri-cookie-consent'); ?></span>
        </label>
      </td>
      <td class="kncc-col-remove">
        <button type="button" class="button-link button-link-delete kncc-url-rule__remove"><?php esc_html_e('Remove', 'kansleri-cookie-consent'); ?></button>
      </td>
    </tr>
    <?php
  }

  private function render_consent_log_section() {
    if (!class_exists('KNCC_Consent_Log')) {
      return;
    }

    $page = isset($_GET['log_page']) ? max(1, absint($_GET['log_page'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $per_page = 25;
    $total = KNCC_Consent_Log::get_total_records();
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $records = KNCC_Consent_Log::get_records($page, $per_page);
    $meta = KNCC_Consent_Log::get_meta();
    ?>
    <hr>
    <h3><?php esc_html_e('Consent Log', 'kansleri-cookie-consent'); ?></h3>
    <p class="description">
      <?php esc_html_e('Individual consent records for GDPR audit compliance. Records are automatically purged after the retention period (13 months).', 'kansleri-cookie-consent'); ?>
    </p>

    <?php
    // created_at is stored in site-local time (current_time('mysql')). mysql2date() formats it
    // as such; wp_date(strtotime()) would read it as UTC and shift it by the site's offset.
    $log_time_format = get_option('date_format') . ' ' . get_option('time_format');
    ?>
    <div class="kncc-consent-log-meta" style="display:flex;gap:18px;margin:1rem 0;font-size:13px;color:#6b7280;">
      <span><strong><?php esc_html_e('Total records:', 'kansleri-cookie-consent'); ?></strong> <?php echo esc_html($total); ?></span>
      <?php if ($meta['oldest']) : ?>
        <span><strong><?php esc_html_e('Oldest:', 'kansleri-cookie-consent'); ?></strong> <?php echo esc_html(mysql2date($log_time_format, $meta['oldest'])); ?></span>
      <?php endif; ?>
      <?php if ($meta['newest']) : ?>
        <span><strong><?php esc_html_e('Newest:', 'kansleri-cookie-consent'); ?></strong> <?php echo esc_html(mysql2date($log_time_format, $meta['newest'])); ?></span>
      <?php endif; ?>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:1rem;">
      <button type="button" class="button" id="kncc-export-log-btn"><?php esc_html_e('Export CSV', 'kansleri-cookie-consent'); ?></button>
      <button type="button" class="button" id="kncc-purge-log-btn" style="color:#dc2626;"><?php esc_html_e('Purge All Records', 'kansleri-cookie-consent'); ?></button>
    </div>

    <?php if (empty($records)) : ?>
      <p style="color:#9ca3af;font-style:italic;"><?php esc_html_e('No consent log records yet.', 'kansleri-cookie-consent'); ?></p>
    <?php else : ?>
      <table class="wp-list-table widefat striped">
        <thead>
          <tr>
            <th><?php esc_html_e('Date/Time', 'kansleri-cookie-consent'); ?></th>
            <th><?php esc_html_e('Visitor', 'kansleri-cookie-consent'); ?></th>
            <th><?php esc_html_e('Choice', 'kansleri-cookie-consent'); ?></th>
            <th><?php esc_html_e('Categories', 'kansleri-cookie-consent'); ?></th>
            <th><?php esc_html_e('Policy Version', 'kansleri-cookie-consent'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $categories = kncc_get_categories();
          $choices = array(
            'accept' => __('Accepted all', 'kansleri-cookie-consent'),
            'reject' => __('Rejected all', 'kansleri-cookie-consent'),
            'custom' => __('Custom selection', 'kansleri-cookie-consent'),
          );
          ?>
          <?php foreach ($records as $row) : ?>
            <?php
            $cats = json_decode($row->categories, true);
            $cats_display = array();
            if (is_array($cats)) {
              foreach ($cats as $k => $v) {
                if ($v) {
                  $cats_display[] = isset($categories[$k]) ? $categories[$k]['label'] : ucfirst($k);
                }
              }
            }
            ?>
            <tr>
              <td><?php echo esc_html(mysql2date($log_time_format, $row->created_at)); ?></td>
              <td><code title="<?php echo esc_attr($row->visitor_hash); ?>"><?php echo esc_html(substr($row->visitor_hash, 0, 12)); ?>&hellip;</code></td>
              <td>
                <span class="kncc-scan-cat kncc-scan-cat--<?php echo $row->choice === 'accept' ? 'necessary' : ($row->choice === 'reject' ? 'marketing' : 'preferences'); ?>">
                  <?php echo esc_html(isset($choices[$row->choice]) ? $choices[$row->choice] : ucfirst($row->choice)); ?>
                </span>
              </td>
              <td><?php echo esc_html(implode(', ', $cats_display) ?: '—'); ?></td>
              <td><?php echo esc_html($row->policy_version); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($total_pages > 1) : ?>
        <div class="tablenav" style="margin-top:0.5rem;">
          <div class="tablenav-pages">
            <?php
            $period = isset($_GET['period']) ? absint($_GET['period']) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $base_url = admin_url('options-general.php?page=kansleri-cookie-consent&tab=statistics&period=' . $period);
            for ($i = 1; $i <= $total_pages; $i++) :
              $url = add_query_arg('log_page', $i, $base_url);
            ?>
              <?php if ($i === $page) : ?>
                <span class="tablenav-pages-navspan button disabled"><?php echo esc_html($i); ?></span>
              <?php else : ?>
                <a class="button" href="<?php echo esc_url($url); ?>"><?php echo esc_html($i); ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php
  }

  public function ajax_export_consent_log() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    $records = KNCC_Consent_Log::get_all_records();

    $csv_lines = array();
    $csv_lines[] = 'Date,Visitor Hash,Choice,Categories,Policy Version';
    foreach ($records as $row) {
      $cats = json_decode($row->categories, true);
      $cats_str = '';
      if (is_array($cats)) {
        $accepted = array();
        foreach ($cats as $k => $v) {
          if ($v) {
            $accepted[] = $k;
          }
        }
        $cats_str = implode('; ', $accepted);
      }
      $csv_lines[] = implode(',', array_map(
        array($this, 'csv_field'),
        array($row->created_at, $row->visitor_hash, $row->choice, $cats_str, $row->policy_version)
      ));
    }

    // BOM so spreadsheet apps read the file as UTF-8.
    wp_send_json_success(array('csv' => "\xEF\xBB\xBF" . implode("\r\n", $csv_lines)));
  }

  /**
   * Quote a value for CSV output.
   *
   * Rows written before the consent endpoint validated its input can contain
   * arbitrary text, so quotes are doubled and leading characters that spreadsheet
   * applications treat as the start of a formula are prefixed with an apostrophe.
   */
  private function csv_field($value) {
    $value = (string) $value;

    if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
      $value = "'" . $value;
    }

    return '"' . str_replace('"', '""', $value) . '"';
  }

  public function ajax_purge_consent_log() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    KNCC_Consent_Log::purge_all();
    delete_option(KNCC_Stats::OPTION_KEY);
    wp_send_json_success(array('message' => __('All consent log records and statistics have been purged.', 'kansleri-cookie-consent')));
  }

  public function ajax_save_settings() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    $lang = kncc_get_current_language();

    // Collect all settings from the form.
    $values = array();

    // All text fields are saved as-is, including empty strings. An empty value means
    // "use the locale default" — the frontend substitutes the visitor's locale default at render time.
    $text_fields = array_merge(array(
      'banner_position',
      'heading_text', 'description_text', 'accept_text',
      'reject_text', 'customize_text', 'save_text', 'close_text',
      'iframe_placeholder_text', 'iframe_accept_text',
    ), kncc_get_builtin_text_keys());
    foreach ($text_fields as $field) {
      if (isset($_POST[$field])) {
        $values[$field] = sanitize_text_field(wp_unslash($_POST[$field]));
      }
    }

    if (isset($_POST['banner_style'])) {
      $style = sanitize_text_field(wp_unslash($_POST['banner_style']));
      $values['banner_style'] = in_array($style, array('bar', 'modal', 'corner'), true) ? $style : 'corner';
    }

    $color_fields = array(
      'banner_bg_color'      => '#ffffff',
      'heading_color'        => '#1f2937',
      'body_color'           => '#4b5563',
      'primary_color'        => '#3D4872',
      'primary_text_color'   => '#ffffff',
      'secondary_color'      => '#f3f4f6',
      'secondary_text_color' => '#4b5563',
    );
    foreach ($color_fields as $field => $default) {
      if (isset($_POST[$field])) {
        $color = sanitize_hex_color(wp_unslash($_POST[$field]));
        $values[$field] = $color ? $color : $default;
      }
    }

    if (isset($_POST['text_style_mode'])) {
      $text_style_mode = sanitize_key(wp_unslash($_POST['text_style_mode']));
      $values['text_style_mode'] = in_array($text_style_mode, array('theme', 'plugin'), true) ? $text_style_mode : 'plugin';
    }

    if (isset($_POST['policy_page_id'])) {
      $values['policy_page_id'] = absint($_POST['policy_page_id']);
    }

    if (isset($_POST['policy_style_mode'])) {
      $policy_style_mode = sanitize_key(wp_unslash($_POST['policy_style_mode']));
      $values['policy_style_mode'] = in_array($policy_style_mode, array('theme', 'plugin'), true) ? $policy_style_mode : 'theme';
    }

    // Checkboxes submit nothing when unchecked, so they can only be written when the
    // form that owns them was the one submitted. Each form declares its checkboxes in
    // kncc_fields[]; without this, saving General would clear consent_mode and saving
    // Integration would clear show_floating_btn.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by array_map below.
    $raw_declared = isset($_POST['kncc_fields']) ? wp_unslash($_POST['kncc_fields']) : array();
    $declared = is_array($raw_declared) ? array_map('sanitize_key', $raw_declared) : array();

    foreach (array('consent_mode', 'consent_mode_advanced', 'show_floating_btn') as $checkbox) {
      if (in_array($checkbox, $declared, true)) {
        $values[$checkbox] = !empty($_POST[$checkbox]);
      }
    }

    if (isset($_POST['empty_category_mode'])) {
      $empty_mode = sanitize_key(wp_unslash($_POST['empty_category_mode']));
      $values['empty_category_mode'] = in_array($empty_mode, array('hide', 'disable'), true) ? $empty_mode : 'disable';
    }

    // Consent Mode is site-wide (the frontend only reads the base setting), so it is never
    // stored as a per-language override.
    $site_wide = array();
    foreach (array('consent_mode', 'consent_mode_advanced') as $site_wide_key) {
      if ($lang && array_key_exists($site_wide_key, $values)) {
        $site_wide[$site_wide_key] = $values[$site_wide_key];
        unset($values[$site_wide_key]);
      }
    }

    if ($lang) {
      // Secondary language: save form values to language-specific option.
      $existing = get_option('kncc_texts_' . $lang, array());
      update_option('kncc_texts_' . $lang, array_merge($existing, $values), false);
    } else {
      // Default language: save to base settings.
      $current = kncc_get_settings();
      update_option('kncc_settings', array_merge($current, $values), false);
    }

    if ($site_wide) {
      update_option('kncc_settings', array_merge(kncc_get_settings(), $site_wide), false);
    }

    delete_transient('kncc_frontend_html');
    wp_send_json_success(array('message' => __('Settings saved.', 'kansleri-cookie-consent')));
  }

  public function ajax_save_cookies() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    $raw = isset($_POST['cookies']) ? wp_unslash($_POST['cookies']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized individually below
    $cookies = array();
    $valid_categories = array('necessary', 'analytics', 'marketing', 'preferences');

    if (is_array($raw)) {
      foreach ($raw as $entry) {
        $name = isset($entry['name']) ? sanitize_text_field($entry['name']) : '';
        if ($name === '') {
          continue;
        }

        $category = isset($entry['category']) ? sanitize_key($entry['category']) : 'necessary';
        if (!in_array($category, $valid_categories, true)) {
          $category = 'necessary';
        }

        $cookies[] = array(
          'name'        => $name,
          'category'    => $category,
          'provider'    => isset($entry['provider']) ? sanitize_text_field($entry['provider']) : '',
          'duration'    => isset($entry['duration']) ? sanitize_text_field($entry['duration']) : '',
          'description' => isset($entry['description']) ? sanitize_text_field($entry['description']) : '',
        );
      }
    }

    // Cookie structure (names, categories, providers, durations) is always shared.
    // Descriptions can differ per language.
    $lang = kncc_get_current_language();

    // The table shows built-in descriptions and durations in this language; store them in English.
    $cookies = kncc_untranslate_cookie_texts($cookies, $lang);

    // Build the base cookie list using default-language descriptions.
    $base_cookies = array();
    $default_descriptions = array();
    $existing_base = kncc_get_cookies();
    foreach ($existing_base as $c) {
      if (!isset($c['name'])) {
        continue;
      }
      $default_descriptions[$c['name']] = isset($c['description']) ? $c['description'] : '';
    }

    foreach ($cookies as $c) {
      $base_cookie = $c;
      if ($lang) {
        // Keep the default-language description in the base option.
        $base_cookie['description'] = isset($default_descriptions[$c['name']]) ? $default_descriptions[$c['name']] : '';
      }
      $base_cookies[] = $base_cookie;
    }

    // Always save structure to the base option.
    update_option('kncc_cookies', $base_cookies, false);

    if ($lang) {
      // Save this language's descriptions separately.
      $desc_map = array();
      foreach ($cookies as $c) {
        if (!empty($c['description'])) {
          $desc_map[$c['name']] = $c['description'];
        }
      }
      update_option('kncc_cookie_descriptions_' . $lang, $desc_map, false);
    }

    $incomplete = array();
    foreach ($cookies as $c) {
      if (empty($c['description'])) {
        $incomplete[] = $c['name'];
      }
    }
    if (!empty($incomplete)) {
      update_option('kncc_incomplete_cookies', $incomplete, false);
    } else {
      delete_option('kncc_incomplete_cookies');
    }

    wp_send_json_success(array('message' => __('Cookies saved.', 'kansleri-cookie-consent')));
  }

  public function ajax_remove_cookies() {
    check_ajax_referer('kncc_admin', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('Unauthorized');
    }

    delete_transient('kncc_frontend_html');

    $raw_names = isset($_POST['names']) ? wp_unslash($_POST['names']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each name sanitized below
    $requested = array();
    if (is_array($raw_names)) {
      foreach ($raw_names as $name) {
        $name = sanitize_text_field($name);
        if ($name !== '') {
          $requested[] = $name;
        }
      }
    }

    if (empty($requested)) {
      wp_send_json_error(__('No cookies selected.', 'kansleri-cookie-consent'));
    }

    $stale_names = array();
    foreach (KNCC_Service_Cookies::find_stale_cookies() as $cookie) {
      if (!empty($cookie['name'])) {
        $stale_names[] = $cookie['name'];
      }
    }

    $allowed = array_values(array_intersect($requested, $stale_names));
    if (empty($allowed)) {
      wp_send_json_error(__('None of the selected cookies can be removed. Run Scan Cookies again to refresh the list.', 'kansleri-cookie-consent'));
    }

    $allowed_lookup = array_fill_keys($allowed, true);
    $cookies = kncc_get_cookies();
    $remaining = array();

    foreach ($cookies as $cookie) {
      $name = isset($cookie['name']) ? $cookie['name'] : '';
      if ($name !== '' && isset($allowed_lookup[$name])) {
        continue;
      }
      $remaining[] = $cookie;
    }

    update_option('kncc_cookies', $remaining, false);

    foreach (kncc_get_language_slugs() as $lang) {
      $option_name = 'kncc_cookie_descriptions_' . $lang;
      $desc_map = get_option($option_name, array());
      if (!is_array($desc_map)) {
        continue;
      }
      $changed = false;
      foreach ($allowed as $name) {
        if (isset($desc_map[$name])) {
          unset($desc_map[$name]);
          $changed = true;
        }
      }
      if ($changed) {
        update_option($option_name, $desc_map, false);
      }
    }

    $incomplete = KNCC_Scanner::get_incomplete_cookies();
    if (!empty($incomplete)) {
      update_option('kncc_incomplete_cookies', $incomplete, false);
    } else {
      delete_option('kncc_incomplete_cookies');
    }

    wp_send_json_success(array(
      'removed' => $allowed,
      'message' => __('Selected cookies removed from the list.', 'kansleri-cookie-consent'),
    ));
  }
}
