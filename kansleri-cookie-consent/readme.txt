=== Kansleri Cookie Consent ===
Contributors: tapiokansleri
Tags: cookie consent, gdpr, google consent mode, cookie banner, privacy
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.8.11
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, no-ads, no-pro-version GDPR cookie consent with script blocking, consent logging, Google Consent Mode v2, and AI-assisted setup.

== Description ==

Kansleri Cookie Consent is a free, lightweight, and easy-to-use WordPress cookie consent plugin. There are no ads, no pro version, and no upsells. It is perfect for personal websites, small businesses, and hobby sites who want no-hassle cookie consent.

**Features:**

* Three banner styles: bottom bar, centered modal, corner popup
* Customizable colors, texts, and button labels
* Plugin or Theme typography mode for consistent banner styling (Plugin style is the default)
* Four consent categories: Necessary, Analytics, Marketing, Preferences
* **Script auto-blocking** — prevents third-party scripts from running until consent is given, including WordPress-enqueued scripts and raw HTML scripts from page builders (Bricks, Elementor, custom code injectors)
* **YouTube & Vimeo iframe blocking** — video embeds are blocked until marketing cookies are accepted, with a user-friendly placeholder (can be turned off)
* **Block by address** — hold back any other script, such as a widget the plugin does not recognize, by part of its address until the visitor accepts the category you choose, with an optional placeholder where it would show content
* **Reviews widgets** — the Trustmary reviews widget waits for analytics consent, with a placeholder and accept button in its place
* Google Consent Mode v2 integration with automatic default/update signals, in Basic mode (Google tags wait for consent) or Advanced mode (Google tags load right away with consent denied)
* GTM dataLayer push on consent changes
* Integration tab recommends Google Site Kit (Google's official WordPress plugin) for installing Google Analytics 4 and Google Tag Manager, so Consent Mode v2 signals work out of the box without manual GTM snippet management
* Built-in cookie scanner that auto-identifies common cookies (Google Analytics, Meta Pixel, WordPress core, and more) — including cookies from consent-blocked scripts via service inference
* AI-assisted cookie descriptions — copy a prompt, paste into an AI tool, import the result
* **AI-assisted policy page generation** — generate a complete cookie/privacy policy page with one prompt
* Cookie policy page defaults to the WordPress Privacy Policy page, with a dynamic `[kncc_cookie_policy]` shortcode (theme styling by default; plugin styling optional)
* Floating settings button for visitors to update preferences (on by default)
* `[kncc_cookie_settings]` shortcode — add a cookie settings link anywhere (footer, menu, etc.) with customizable text
* "Edit cookie preferences" button on the policy page for easy consent withdrawal
* **Per-user consent logging** — anonymized audit trail for GDPR compliance with CSV export
* Consent statistics dashboard (no personal data stored)
* **Graceful multilingual policy page fallback** — if a language is missing a translated policy page, the banner links to the default-language page instead of a 404
* Google Site Kit integration — auto-detects and avoids duplicate consent signals
* WP Consent API integration — shares consent status with other plugins
* **Full WPML and Polylang support** — per-language banner texts, cookie descriptions, banner style, colors, and policy page
* Finnish translation included with auto-localized defaults
* Daily cron check for missing cookie descriptions with admin notifications
* Comprehensive documentations tab with installation guide, option explanations, and troubleshooting
* Clean uninstall — removes all data when deleted

== Installation ==

1. Upload the `kansleri-cookie-consent` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Settings > Cookie Consent** to configure the banner.
4. Use the **Cookies** tab to scan your site and register cookies.
5. The Cookie Policy page defaults to your WordPress Privacy Policy page — add the `[kncc_cookie_policy]` shortcode to that page (or another page you select).

== Frequently Asked Questions ==

= Does this plugin support Google Consent Mode v2? =

Yes. When enabled under the Integration tab, the plugin outputs consent defaults in `<head>` before any other scripts, and sends `consent update` signals whenever a visitor changes their preferences. If Google Site Kit is active, the plugin works alongside Site Kit's own consent defaults.

By default the plugin uses Basic Consent Mode: the Google tag and Google Tag Manager are blocked until the visitor accepts. Turn on Advanced Consent Mode on the Integration tab to load them right away with consent denied. They then set no cookies until the visitor accepts and send Google only cookieless pings, which Google Analytics and Google Ads use to model the missing data. Other tracking scripts stay blocked until consent in both modes.

= How does the cookie scanner work? =

The scanner loads your homepage in a hidden iframe, reads cookies from the browser, and also infers expected cookies from tracking scripts detected on your site (Google Analytics, Meta Pixel, HubSpot, etc.) — even when those scripts are blocked until consent. Known cookies are added automatically; unknown cookies are presented for categorization with an AI-assisted workflow. Expected cookies are also synced when you open the Cookies tab and during the daily cron check.

= Does it work with WPML or Polylang? =

Yes. When WPML or Polylang is active, you can set per-language banner texts, cookie descriptions, banner style, colors, and policy page. Two workflows are supported for banner texts:

1. Switch to a secondary language in the admin bar, edit the General tab, and save — texts are stored per language (`kncc_texts_{lang}`) and take priority.
2. With WPML String Translation, translate the `[kncc_settings]*` admin text strings. Those translations are used when no per-language override is saved for that language.

If neither is set, the plugin falls back to that language's own plugin defaults (English if the language is not yet translated). Banner texts are never copied blindly from the default language. Non-text settings (colors, style) still inherit from the default language unless overridden. Cookie names, categories, providers, and durations are shared across all languages.

= Does it work with Google Site Kit? =

Yes. The plugin detects Site Kit automatically. Site Kit's consent mode sets denied defaults for its own regions (currently the EEA, the UK and Switzerland); this plugin adds a denied default for all other visitors and sends consent updates when visitors make a choice. Consent Mode v2 is forced on when Site Kit is active.

= Does it work with WP Consent API? =

Yes. When the WP Consent API plugin is active, consent status is automatically shared with other plugins that support the API.

= How are consent statistics tracked? =

Statistics are tracked server-side via lightweight AJAX calls when visitors make a consent choice. No personal data, IP addresses, or tracking cookies are used. Only daily aggregates (accept/reject/custom counts) are stored.

= Does it block YouTube and Vimeo embeds? =

Yes. YouTube and Vimeo iframes are automatically blocked until the visitor accepts marketing cookies. A placeholder is shown in place of the video with an "Accept marketing cookies" button. If the visitor has already accepted cookies via the consent banner, videos load normally without any placeholder. You can turn embed blocking off on the Script Blocking tab.

= How do I add a cookie settings link to my footer? =

Use the shortcode `[kncc_cookie_settings]` in any widget, page, or template. You can customize the link text with `[kncc_cookie_settings text="Manage cookies"]`. The shortcode is also shown with a copy button in the plugin settings page.

= What happens when the plugin is uninstalled? =

All plugin options (`kncc_settings`, `kncc_cookies`, `kncc_consent_stats`, `kncc_incomplete_cookies`, `kncc_show_activation_notice`, `kncc_defaults_localized`, `kncc_cron_error`, `kncc_blocked_scripts`, `kncc_custom_blocked_scripts`, `kncc_blocked_url_patterns`, migration flags, and per-language `kncc_texts_*` / `kncc_cookie_descriptions_*` options), the consent log table, and the daily cron schedule are removed from the database. Your WordPress Privacy Policy page is never deleted.

== Screenshots ==

1. Cookie consent banner on the frontend (corner popup style)
2. General settings — customize banner style, colors, typography, and button texts
3. Cookies tab — scan your site, auto-detect cookies, and use AI to generate descriptions
4. Policy Page tab — select your policy page and generate a complete privacy policy with one AI prompt
5. Integration tab — Google Consent Mode v2 and Site Kit / WP Consent API status
6. Script Blocking tab — auto-detected third-party scripts blocked before consent
7. Statistics tab — consent response summary with accept, reject, and custom breakdown
8. Consent Log — anonymized per-visitor audit trail with CSV export
9. Blocked YouTube embed showing the video thumbnail with an "Accept marketing cookies" button before consent

== Changelog ==

= 1.8.11 =
* Fixed: Version 1.8.10 added cookies to the cookie list for services that are not on the site (Google Analytics, Meta, HubSpot, LinkedIn, Plausible, Microsoft Clarity and Trustmary), and they came back after you removed them. The plugin found the tracker addresses that its own script blocker lists in every page and took them for installed services. It now ignores its own scripts. The update removes the cookies that 1.8.10 added this way and lists them on the plugin page, with a button to put them back if your site does use some of these services. Cookies you added or edited yourself stay. On sites with Google Tag Manager, which can load any of these services, nothing is removed: the plugin page lists the cookies to check instead
* Fixed: When you move Polylang's language cookie `pll_language` out of the Necessary category, it is no longer set before the visitor allows that category. It is Necessary by default, and then nothing changes
* Fixed: Speed plugins that run delayed scripts one after another (for example LiteSpeed Cache, Debloat and WP Rocket in Firefox) can no longer get stuck on a tracking script that waits for consent: the scripts after it still run
* Fixed: A Google Ads tag from Site Kit without Google Analytics now loads when the visitor accepts analytics or marketing cookies, as the same tag does elsewhere since 1.8.10
* Fixed: A Block by address rule that includes your own site's address now also matches scripts that the page links with a relative address (/wp-content/...)

= 1.8.10 =
* Fixed: Tracking scripts that Google Tag Manager adds to the page now wait for their own category, with no changes needed in the container. Before, a Meta Pixel in a Custom HTML tag ran as soon as the visitor accepted analytics, even with marketing denied, and with Advanced Consent Mode it ran before any choice. The same applies to LinkedIn, TikTok, Hotjar and every other script the plugin knows, and to your Block by address rules, whatever code adds them after the page has loaded
* Fixed: With Basic Consent Mode, Google Tag Manager and a Google Ads tag on its own (gtag.js with an AW- ID) now load when the visitor accepts analytics or marketing cookies. Before, they waited for analytics only, so a visitor who allowed only marketing got no Meta Pixel or Google Ads tags. Google Analytics, in the container or on its own, still waits for analytics consent. Consent Mode keeps telling each Google tag what it may store, as before

= 1.8.9 =
* New: Every text visitors see can now be edited. The General tab adds the cookie category names and descriptions, the "no cookies are used" notes, the Cookie Policy link text, the floating button label (also the default text of the `[kncc_cookie_settings]` link), the banner's screen reader label, and the messages and buttons on blocked analytics and preferences content. The Policy Page tab adds every text of the `[kncc_cookie_policy]` cookie declaration: category descriptions, table headings, the consent status texts and the edit button
* Empty fields keep the built-in text, translated into each language, so nothing changes on your site until you type your own wording. On multilingual sites (Polylang, WPML) each language can have its own wording

= 1.8.8 =
* Fixed: Descriptions and durations of the cookies the plugin adds itself (from Scan Cookies and detected scripts, e.g. `_ga`, `kncc_consent` and `wp_consent_*`) were always in English, even on Finnish sites. The cookie policy page now shows them in the visitor's language, and the Cookies tab in the language you are editing. This also covers cookies already in your list, so there is nothing to re-scan or re-save. Descriptions you have written or edited yourself are shown as written
* New: Finnish translations for all built-in cookie descriptions and durations. Other languages can translate them like the rest of the plugin
* New: Block by address on the Script Blocking tab. Enter part of a script's address (for example widget.example.com) and the category it needs, and the script waits for that consent wherever it is added, including scripts printed straight into page HTML by a theme or page builder. Turn on Placeholder for scripts that show content, such as a reviews widget, to show a message and an accept button in their place. A rule also overrides the built-in category for the same address; choose "Necessary (never blocked)" to let a script load right away
* New: The Trustmary reviews widget (widget.trustmary.com and embed.trustmary.com) now waits for analytics consent, because it stores a visitor ID, the landing page and conversions in the browser's localStorage before any choice. A placeholder with an accept button is shown in its place, and the `tmary` item is listed in the cookie policy. **This changes existing sites:** the widget no longer shows until the visitor accepts analytics cookies. To keep the old behaviour, add widget.trustmary.com under Block by address as "Necessary (never blocked)"
* New: When a visitor rejects a category, items with the exact name of that category's registered cookies are also removed from localStorage
* Fixed: Accepting through a blocked video's (or widget's) accept button now also runs the other scripts held back for that category right away, not only on the next page load
* New: These services are now held back until the visitor accepts their category, where before they ran straight away: Reddit Pixel, Microsoft Advertising (Bing UET), Criteo, Taboola, Klaviyo, Mailchimp pop-ups and Trustpilot (marketing); Fathom, Cloudflare Web Analytics, Yandex Metrica and Segment (analytics); and the Intercom, Crisp and Calendly widgets (preferences, since the visitor has not asked for them yet). Scan Cookies now recognizes their cookies too. **This changes existing sites:** a chat or booking widget no longer shows until the visitor accepts preference cookies. To keep one working for everyone, add its address (for example widget.intercom.io) under Block by address as "Necessary (never blocked)"
* Fixed: With Autoptimize's "Also aggregate inline JS" on, Google Tag Manager, Meta, TikTok, Pinterest, X, Snapchat, LinkedIn, Clarity, Hotjar and Matomo code ran before consent, because Autoptimize merged it into a file on your own domain before this plugin saw the page. The plugin now keeps the scripts it holds back out of Autoptimize's merged file
* Fixed: A block of code that mixes several services, such as one an optimizer has put together, is now held for the strictest of their categories instead of the first one found. Marketing code in a mixed block no longer runs on analytics consent alone
* Fixed: The banner's buttons and spacing no longer shrink on themes that set a small root font size, such as OceanWP, where the buttons were 32px high and the × could overlap a button
* Fixed: WooCommerce Order Attribution set its marketing cookies (`sbjs_*`) on every page before any choice. Its scripts now wait for marketing consent. **Orders placed by visitors who have not accepted marketing cookies no longer record where the visitor came from.** The `wp_woocommerce_session_*` cookie is now recognized as a WooCommerce cookie
* Fixed: A category's switch in the banner stayed switched off until Scan Cookies had registered a cookie for it, even while scripts were being held back for that category, so a visitor choosing "Customize" could not allow them. The switch is now offered as soon as a script has been held back for the category
* Fixed: A script whose address is written without quotes (`src=https://…`, as some HTML minifiers produce) was not recognized and ran before consent
* Fixed: With LiteSpeed Cache set to delay all JavaScript until the first interaction, the plugin's own scripts were delayed too, so a first-time visitor saw no banner until they scrolled or clicked. LiteSpeed is now told to leave them alone

= 1.8.7 =
* Fixed: YouTube and Vimeo videos added with Elementor's Video widget are now blocked until marketing consent, including the image overlay and lightbox options. Elementor builds its YouTube player in the browser, so it used to load before consent. After consent the video appears without a page reload. Turning off embed blocking on the Script Blocking tab leaves these widgets to Elementor too

= 1.8.6 =
* Fixed: With Elementor and the Hello Elementor theme, the floating cookie settings button had shrunk to a narrow strip since 1.8.4; it is a centered round button again
* Fixed: Banner buttons, the × button and the blocked-video accept button now keep the colors from the plugin settings instead of taking Elementor's global button colors and Hello Elementor's borders and hover colors
* Note: If you recolored buttons with custom CSS, use `#kncc-floating-btn` for the floating button and prefix banner button rules with `.kncc-banner` (e.g. `.kncc-banner .kncc-btn--primary`), or add `!important`

= 1.8.5 =
* New: Advanced Consent Mode option on the Integration tab (off by default). When on, the Google tag and Google Tag Manager load right away with all consent signals denied, instead of being blocked until the visitor accepts. They set no cookies until the visitor accepts and send only cookieless pings until then, so Google Analytics and Google Ads receive the consent state of every visitor. Other tracking scripts stay blocked until consent. **If Google Analytics or Google Ads shows your consent signals as not active, turn this on.**
* New: YouTube and Vimeo embed blocking can be turned off on the Script Blocking tab (on by default)
* Fixed: With WP Rocket's Delay JavaScript on, tracking scripts that WP Rocket had already delayed (current versions mark them `text/rocketlazyloadscript`), such as the Google Tag Manager snippet added by Site Kit, were not blocked and ran on the visitor's first interaction, before consent. They now wait for consent, and delayed external scripts keep their URL so they load once the visitor accepts
* Fixed: With Google Site Kit's consent mode on, a returning visitor's saved choice was sent to Google before Site Kit's consent default. Google expects the default first, so the update now follows it
* Changed: With Google Site Kit's consent mode on, the plugin also sets a denied consent default for visitors outside Site Kit's regions, so Google tags wait for their choice too

= 1.8.4 =
* Fixed: The floating cookie settings button no longer shrinks to a tiny icon on themes that add padding to buttons (e.g. Flatsome and Storefront), and the banner's × button keeps its size on Flatsome
* Fixed: In the bottom bar banner, the × button no longer covers the Customize button at common laptop and tablet widths
* Fixed: Script blocking no longer breaks the LinkedIn Insight Tag and TikTok pixel (their loader code was being altered, so they never loaded after consent), and tracking scripts with any JavaScript type, such as `type="application/javascript"`, are now blocked instead of running before consent
* Fixed: A Google Tag Manager snippet pasted in the same script block as a consent default is now blocked until consent
* Fixed: With Google Site Kit active, Consent Mode v2 defaults are printed again; the plugin assumed Site Kit was handling them even when Site Kit's own consent mode was off. Consent Mode is now on whenever Site Kit is active, as the Integration tab already stated
* Fixed: Consent updates now reach Google tags even when no other script has defined `gtag()` yet
* Fixed: Scripts re-enabled after consent keep their attributes, so Plausible (`data-domain`) and Fathom (`data-site`) track again; module scripts run as modules
* Fixed: Accepting marketing cookies on a blocked video after "Reject all" now also updates the WP Consent API and the policy page consent status
* Fixed: WP Rocket's Delay JavaScript no longer delays the plugin's own consent scripts
* Fixed: Multilingual sites — Consent Mode is one site-wide setting on every language, and saving the Policy Page tab on a secondary language no longer removes that language's policy link
* Fixed: A visitor who changes their choice within a minute is now recorded in the consent log and statistics
* Fixed: Consent log times were shown shifted by the site's time zone offset
* Fixed: Banner texts are saved in the site's language on activation, even when the activating admin uses another profile language
* Fixed: The blocked-video placeholder text no longer appears in automatic excerpts and RSS feeds
* Fixed: Google Analytics cookies are no longer declared on sites that use Site Kit without Google Analytics
* Fixed: Scanned HubSpot cookies `__hstc`, `__hssc` and `__hssrc` now get their own category and duration
* Fixed: Uninstalling on multisite now removes the plugin's data from every site
* Security: Cookie names are escaped correctly in the Scan Cookies results

= 1.8.3 =
* Fixed: Saving the General tab silently switched off Google Consent Mode v2, and saving the Integration or Policy Page tab silently switched off the floating settings button. **Please open the Integration tab and confirm Consent Mode v2 is still enabled after updating.**
* Fixed: Blocked YouTube and Vimeo embeds are now blocked identically for every visitor, so a page cache can no longer serve an unblocked embed to someone who never consented. Visitors who already consented still get their videos back automatically.
* New: Embeds added through page builders (Bricks, Elementor, custom code) are now blocked too, not only those in post content
* Fixed: The consent log table could never be upgraded because `CREATE TABLE IF NOT EXISTS` confused WordPress's schema updater
* Security: The public consent-recording endpoint now validates the submitted categories, throttles repeat submissions, and no longer stores raw client input
* Security: Consent log CSV export escapes quotes and neutralizes spreadsheet formula characters
* Security: Visitor identifiers in the consent log are now keyed with the site salt, so they cannot be brute-forced back to an IP address
* Fixed: Consent statistics stopped recording on cached sites once the embedded nonce aged out
* Fixed: The Copy button next to the `[kncc_cookie_settings]` shortcode did nothing; the Copy CSS template button no longer mangles the template in a single-line prompt
* Improved: Banner keyboard accessibility — focus moves into the banner when it is reopened, returns to the control that opened it, Escape closes it, and Tab stays inside the centered modal
* Improved: `aria-modal` is now only applied to the centered modal style, which is the only style that blocks the page
* Fixed: Running Scan Cookies no longer leaves the administrator's own browser with accept-all consent
* Fixed: Scanning could fail with a PHP 8 error on unexpected input
* Improved: Faster translation loading via a bundled `.l10n.php` file
* Tested up to WordPress 7.1

= 1.8.2 =
* Fixed: WPML String Translation of banner texts (`[kncc_settings]*`) works again on secondary languages after the 1.8.0 per-language defaults change — overrides in `kncc_texts_{lang}` still take priority, then WPML ST, then language plugin defaults

= 1.8.1 =
* Changed: Default banner style is now Corner popup instead of Bottom bar (existing sites keep their saved style)
* New: WordPress Playground Live Preview blueprint (`assets/blueprints/blueprint.json`) for the official plugin directory preview button

= 1.8.0 =
* Improved: Accept / Reject / Save preferences activate blocked scripts and iframes immediately — no page reload required
* Fixed: After Reject all, Accept all can re-activate the same blocked scripts without refreshing the page
* Fixed: Cookie scanner needed two manual runs to find WP Consent API bridge cookies (`wp_consent_*`) and other consent-gated trackers — one Scan click now seeds consent, reloads once, then collects
* Changed: Plugin no longer auto-creates a Cookie Policy page — the Cookie policy page dropdown defaults to the WordPress Privacy Policy page (Settings → Privacy)
* Fixed: Polylang/WPML secondary languages no longer inherit banner texts from the default language — each language uses its own plugin defaults, with English as fallback when a language is unsupported
* Fixed: Modal close (×) no longer pushes banner content to the left on mobile — only the heading reserves space for the icon
* Improved: Blocked iframe message / accept button fields use per-language defaults (same as other banner texts)

= 1.7.2 =
* Fixed: `wp_consent_statistics` could stay `deny` after Accept All — WP Consent API cookies now sync server-side from `kncc_consent` on every request, with an early `wp_head` fallback and hardened client-side sync (including `statistics-anonymous`)
* Fixed: `wp_consent_statistics` deny persisting due to a URL-encoded `kncc_consent` cookie — the server now URL-decodes the cookie before syncing, and the consent choice (accept/reject/custom) is stored so a missing category key can no longer leave statistics stuck on deny
* Fixed: WP Consent API client sync now writes cookies even when the WP Consent API script is delayed, dispatches a consent-change event for Google Site Kit, and clears stale statistics cookies on parent domains
* Improved: Cookie policy page notes the technical WP Consent API cookie names (wp_consent_functional, wp_consent_statistics, etc.) so the mapping from banner labels is clear
* Fixed: Scanner no longer flags `wp_consent_statistics-anonymous` as unknown — it is now auto-recognized and described as a consent-record cookie
* Fixed: Copy AI Prompt now includes every cookie the plugin knows about — the scan returns the full saved cookie list, which is unioned with the scan results and the current table rows (your live edits take priority) so no cookies are dropped due to scan/save/reload timing
* Fixed: Copy AI Prompt on the Cookies and Policy Page tabs no longer uses a browser prompt dialog — the full prompt is shown in a selectable textarea below the button, with reliable clipboard copy on both http and https (fixes truncated/garbled JSON on local sites)
* Improved: AI cookie prompt now asks the AI to verify and correct each cookie's category (not just the description), with clear category definitions so tracking cookies are not mislabeled as Necessary
* Changed: Default banner typography is now Plugin style (strict sizes/colors) instead of Theme style — the select field remains so you can switch back
* Unchanged: Cookie policy page still defaults to Theme style so it matches your site typography; only the consent banner/popup uses Plugin style by default
* Improved: WP Consent API bridge cookies (`wp_consent_functional`, `wp_consent_statistics`, `wp_consent_marketing`, `wp_consent_preferences`, `wp_consent_statistics-anonymous`) are now categorized as Necessary — they only store consent choices, they do not perform tracking
* Improved: Jetpack stats cookies (`tk_ai`, `tk_qs`) are auto-categorized as Analytics; WooCommerce Order Attribution / Sourcebuster cookies (`sbjs_*`) are auto-categorized as Marketing
* Improved: Admin JS/CSS assets use file modification time for cache busting so updated scripts load immediately after plugin updates
* Fixed: Custom save with hidden empty categories now explicitly denies missing optional categories so stale consent keys cannot linger
* Fixed: Floating cookie settings button no longer hardcodes white for the icon — background and icon colors now follow the Primary button and Primary button text settings
* New: Cookie scanner detects cookies whose tracking provider is no longer on the homepage (e.g. after removing Google Analytics) and lists them under "Possibly removed"
* New: Admin can review and confirm removal of stale cookies from the scan results — nothing is deleted automatically
* New: Cookie policy page shows your current consent status (allowed, denied, or not chosen) above the "Edit cookie preferences" button
* Changed: Frontend banner close control is now a × icon in the top-right corner, matching the admin banner preview — the text "Close" link in the button row is removed
* Changed: "Close button text" setting renamed to "Close button label" — it sets the screen reader label only; the visible button is always ×
* Improved: Banner preview and live frontend now share the same close-button styling via consent.css

= 1.7.1 =
* Fixed: Google Analytics cookies (`_ga`, `_ga_*`, `_gid`) could remain visible after rejecting analytics or withdrawing consent — stale cookies are now cleared on page load when analytics is denied, on Reject all, and when analytics is turned off in custom preferences
* Fixed: Non-essential cookies for rejected categories (analytics, marketing, preferences) are now removed using registered cookie categories and built-in scanner patterns — not just Google Analytics
* Fixed: Google Site Kit Google Tag Gateway scripts (`google_gtagjs-*` handles and `gtg/measurement.php`) were not blocked before consent
* Fixed: WP Rocket Delay JavaScript could rewrite blocked scripts to `rocketlazyloadscript`, bypassing consent blocking — HTML script blocking now normalizes those tags back to `text/plain`
* New: WP Rocket compatibility — registers `kncc_consent` as a dynamic cache cookie and excludes consent/GA scripts from Delay JS
* Improved: Accept all now reloads the page after saving consent so blocked scripts and tracker cookies load reliably on the next request (same pattern as Reject all)
* Improved: Custom save always reloads the page after saving preferences so blocked scripts and category cookies apply correctly (same pattern as Accept all and Reject all)
* Improved: WP Consent API bridge cookies (`wp_consent_functional`, `wp_consent_statistics`, `wp_consent_marketing`, `wp_consent_preferences`) are now auto-described in the Cookies tab when scanned or inferred
* Improved: Known cookies that were previously saved without descriptions are backfilled automatically when the Cookies tab loads or during scan/cron sync

= 1.7.0 =
* Fixed: Google Analytics and other consent-blocked tracker cookies not appearing during cookie scan
* New: Service-based cookie inference — expected cookies are registered automatically when tracking scripts are detected on your site, even before consent is granted
* New: Cookie inference runs on Scan Cookies, when opening the Cookies tab, and during the daily cron check
* New: Custom Code blocking for page builders and header/footer injectors (Bricks, Elementor, etc.) — toggle on Script Blocking tab, enabled by default
* New: Microsoft Clarity auto-blocking and cookie inference
* Fixed: GA4/gtag snippets pasted into Custom Code fields running before consent
* Improved: Browser scan waits up to 8 seconds and grants temporary scan consent so runtime cookies can be discovered
* Improved: Scan results now show inferred cookies and filtered browser-only cookies separately
* Improved: Script Blocking tab copy simplified for non-technical users, with guidance on when to turn off Custom Code blocking

= 1.6.2 =
* Fixed: WordPress 6.7+ "Translation loading for the kansleri-cookie-consent domain was triggered too early" notice. Two early triggers were calling `__()` before the `init` action: the Consent Mode class was reading settings (and therefore the translated defaults) in its constructor on `plugins_loaded`, and the one-time default-text localization migration ran on `plugins_loaded:20`. The Consent Mode setting check now happens inside the `wp_head` callback, and the migration is hooked on `init:5`.

= 1.6.1 =
* New: "Close" button text is now a customizable setting in the General tab — previously it was a hardcoded translation string, which made it impossible to override per language on sites using Polylang or WPML. Add or edit it like any other banner button label, with per-language overrides via the admin-bar language filter. Reported by a multilingual user running Catalan / Spanish / English.
* Tested with WordPress 7.0.

= 1.6.0 =
* New: Empty cookie categories — when a category (e.g. Marketing) has no registered cookies, the banner can now either hide that category from the customize panel or show it with a locked (disabled) switch. Default is "Show but lock" so visitors still see what categories exist while being clearly told nothing is collected. Switch in **Settings → Cookie Consent → General → Empty cookie categories**, with live preview.
* New: Per-category empty note — locked empty categories display a small italic note such as "No marketing cookies are used on this site." (translatable per category, so Finnish and other inflected languages get proper wording).
* Fixed: WP Consent API integration was non-functional and could break analytics when Google Site Kit Consent Mode was active. The bridge that syncs `kncc_consent` to WP Consent API now loads after the WP Consent API script, so `wp_set_consent` actually runs and dispatches the `wp_listen_for_consent_change` event that consent-aware integrations (Site Kit, Complianz, others) listen for.
* Fixed: The plugin now declares itself as an opt-in consent manager via the `wp_get_consent_type` filter. Without this, `window.wp_fallback_consent_type` was empty and Site Kit's Consent Mode JS short-circuited — leaving `analytics_storage` stuck at `denied` even after visitors accepted analytics cookies. Other consent-aware plugins now also receive correct `wp_has_consent()` results instead of an always-true response. The filter yields to any other plugin that already set the consent type.
* Fixed: WP Consent API registration filter used the wrong handle (slug only instead of the full plugin basename), so the plugin appeared as "not registered" in Site Health > WP Consent API. Now uses the correct basename.

= 1.5.1 =
* New: Documentations tab now ships a Custom CSS template — every selector the plugin uses, presented as one copy-paste-ready CSS block with a one-click "Copy CSS template" button. Uncomment the rules you want to customize, paste into Customizer or your child theme, save.
* Improved: Category toggles redesigned with an outlined "off" state that tracks the body text color, and an "on" knob that tracks the primary text color — so the on/off states stay visible at any color combination, including pure-white primary buttons.
* Improved: "Customize" and "Close" link buttons in the banner now have a clear hover state — subtle background tint with rounded corners and a thicker underline, so the click target is obvious instead of relying only on a small color shift.
* Improved: "Close" button now uses the body text color so it reads as a quiet dismissal action rather than competing for attention with "Customize" and the primary buttons.
* Improved: Banner preview's close (×) icon follows the body text color picker, so it stays visually integrated as the admin tunes the palette.
* Removed: "Border / divider" color picker and the matching divider line in the details panel — one less rarely-changed setting in the General tab, and a cleaner banner layout.

= 1.5.0 =
* New: Integration tab now recommends Google Site Kit (Google's official WordPress plugin) for adding Google Analytics 4 or Google Tag Manager — Site Kit handles Consent Mode v2 natively alongside Kansleri, so no manual GTM snippet, script blocking tweaks, or container ID management is needed. A one-click link takes you straight to the Site Kit install page.
* New: Cookie scanner filters out "browser-only" third-party cookies — when the scanner sees a tracker cookie (GA, Meta Pixel, HubSpot, LinkedIn, Hotjar, Plausible) but the matching script is not actually in the homepage HTML, the cookie is silently dropped. Fixes false positives on subdomain installs where parent-domain cookies (like `_ga`) bleed into the admin's browser even though this site has no tracker.
* New: Live banner preview reworked from a fullscreen lightbox into an in-place floating popup — the "Open banner preview" button sits at the top of the General tab, and clicking it shows the banner in its real position (bar at bottom, modal centered, corner at corner). Colors, text, banner style, and typography updates appear in the preview in real time while the form stays editable behind it.
* Improved: Banner preview "Centered modal" variant now positions itself in the actual screen center.
* Improved: Banner preview Customize button toggles the categories panel like the real frontend banner; the panel starts collapsed.
* Improved: Banner preview Necessary-category toggle no longer shows a stray native checkbox alongside the custom toggle UI.
* Improved: Banner preview is automatically disabled when Theme style is selected, with a clear notice that Theme style depends on the frontend theme's CSS which doesn't load inside /wp-admin/.
* Improved: Color picker grid in the General tab — each color swatch now shows its purpose label (Banner background, Primary button, etc.) above it.
* Improved: Blocked YouTube iframe placeholder — switched from `hqdefault.jpg` (4:3 with baked-in black letterbox bars) to `mqdefault.jpg` (native 16:9), and added defensive resets so theme styles like `.entry-content img { margin: 2em 0; border-radius: 4px; }` no longer leak into the thumbnail and create gaps or rounded edges. A subtle radial gradient keeps the message text and Accept button readable against any thumbnail.
* Improved: Iframe placeholder mobile layout — tighter padding under 600px and `box-sizing: border-box` on the overlay so the message text no longer overflows the right edge on narrow screens.
* Improved: Integration tab copy rewritten for non-technical users — fewer technical terms, clearer recommendations.
* Improved: Removed the now-discouraged `load_plugin_textdomain()` call — translations on translate.wordpress.org are loaded automatically by WordPress core.

= 1.4.0 =
* New: Empty banner text fields fall back to the visitor's locale default at render time — each language gets its own locale-appropriate defaults (English by default, Finnish only on the Finnish version). Cleared fields stay empty in the database, so the admin can leave a field empty to "use the default" without the form filling itself back in on save.
* New: Default values shown as placeholders in the admin form so it's obvious what visitors would see if a field is left empty
* New: Graceful fallback to default-language policy page when a language is missing its own translation (both Polylang and WPML)
* New: Dead policy-page links are no longer rendered — if the selected page is deleted, trashed, or unpublished, the banner omits the link instead of pointing to a 404
* Improved: Admin language notice is clearer — a warning when editing a secondary language (saves target that language only), and an info notice when editing the default language so the active save target is never ambiguous
* Improved: Polylang's "Show all languages" admin-bar filter is now treated as the default language, including under Polylang's WPML compatibility mode — no more misleading "language: ALL" warnings
* Improved: WPML policy-page resolution now uses the same default-language fallback logic as Polylang, with explicit publish-status checks for every resolved translation
* Removed: Admin warning about missing policy-page translations (no longer needed, replaced by the graceful fallback)
* Updated: Finnish translations for all new and changed admin strings

= 1.3.0 =
* New: Policy page style setting — choose theme or plugin styling for the [kncc_cookie_policy] cookie declaration table
* Improved: Cookie policy table is now mobile-responsive — stacks into card layout on small screens
* Improved: Policy page theme mode adapts to dark and light backgrounds
* Improved: "Edit cookie preferences" button moved to top of policy page
* Fixed: Daily cron check not re-scheduling after plugin updates
* Updated: Finnish translations

= 1.2.1 =
* Fixed: Direct file access protection added to uninstall.php
* Updated: Finnish translations for documentation tab strings

= 1.2.0 =
* New: Full WPML and Polylang multilingual support — per-language banner texts, cookie descriptions, banner style, colors, and policy page
* New: Per-language cookie descriptions — cookie structure is shared, only descriptions are translatable
* New: AI prompts auto-generate in the current admin language
* Improved: Reliable language detection during AJAX saves for Polylang compatibility
* Improved: Iframe placeholder sizing — proper min-height and no overflow on mobile
* Improved: Documentation tab with multilingual guide, shortcode reference, and iframe blocking docs
* Fixed: Cookie deletion not persisting when saving from a secondary language
* Fixed: Scanner result strings showing mixed languages

= 1.1.0 =
* New: YouTube and Vimeo iframe blocking — video embeds are blocked until marketing cookies are accepted
* New: `[kncc_cookie_settings]` shortcode to add a cookie settings link anywhere (footer, menu, widgets)
* New: Shortcode info box with copy button in admin settings
* Improved: Page reload on "Reject all" to ensure blocked content is properly hidden
* Improved: Server-side consent cookie check to avoid unnecessary placeholders for returning visitors
* Updated: Finnish translations for all new strings

= 1.0.0 =
* Initial release
