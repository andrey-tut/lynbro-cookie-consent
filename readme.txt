=== Lynbro Cookie Consent ===
Contributors: lynbro
Tags: cookie consent, cookie banner, cookie notice, gdpr, consent mode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, multilingual cookie consent banner — GDPR/ePrivacy & Google Consent Mode v2 ready, equal Reject button, no pageview limits.

== Description ==

A free, multilingual and fully configurable cookie consent banner for WordPress.
It is GDPR/ePrivacy and Google Consent Mode v2 ready out of the box, with an
equally prominent "Reject all" button (in line with Datatilsynet / EDPB guidance)
and no pageview or domain limits. Everything runs locally — no account, no cloud,
no external requests on the front end.

This plugin helps you with cookie compliance but does not constitute legal advice.

Source code and issue tracker on GitHub:
https://github.com/andrey-tut/lynbro-cookie-consent

**Free features:**

* Accessible banner in the footer with equal Accept all / Reject all / Save
  preferences buttons (rejection-equality, no dark patterns).
* Granular consent categories: Necessary (always on), Preferences, Statistics,
  Marketing — with per-category toggles and descriptions.
* Script blocking before consent: mark scripts as
  `type="text/plain" data-lynbro-cc="statistics|marketing"` and they only run
  after the matching category is granted. Helper function included.
* Google Consent Mode v2 — free: all signals default to "denied" before consent
  (ad_storage, analytics_storage, ad_user_data, ad_personalization,
  functionality_storage, personalization_storage, security_storage), updated
  after the visitor chooses.
* Geo-aware consent model: EU/EEA/UK → opt-in, US → opt-out (CCPA/CPRA), rest of
  world → notice. Region detected from CDN/edge headers (e.g. Cloudflare); safe
  opt-in fallback when unknown. No external geolocation API.
* Embed/iframe consent placeholders: YouTube, Vimeo, Google Maps, social embeds,
  reCAPTCHA and more are blocked until consent and replaced with a tidy,
  translatable placeholder with an "allow" button (and an optional "always allow
  this service" choice). Zero layout shift — placeholders reserve the iframe size.
* Automatic blocking of well-known trackers by a built-in catalog (Google
  Analytics/GA4, Google Tag Manager, Meta Pixel, Hotjar, Microsoft Clarity,
  LinkedIn, TikTok and more) in addition to manual script marking. Extendable via
  a filter.
* Global Privacy Control (GPC): honors the browser opt-out signal and, in
  opt-out mode, automatically applies it with a visible confirmation (California
  CPRA 2026).
* CCPA / CPRA "Do Not Sell or Share My Personal Information" link in opt-out mode.
* Cache & performance friendly: the consent core is automatically excluded from
  delay/defer/minify/combine of WP Rocket, LiteSpeed, SiteGround Optimizer,
  Autoptimize and Cloudflare Rocket Loader, with zero layout shift (CLS).
* Full customization without code: light/dark/auto theme (follows the visitor's
  system), separate light and dark color sets, position (bottom bar, top bar,
  floating box with corner choice, center modal), live admin preview and a
  WCAG-AA contrast validator.
* Floating "Cookie settings" button (toggle, corner, label, icon) plus the
  shortcode `[lynbro_cookie_settings]` to re-open the banner at any time.
* Local consent log (proof of consent) stored in your own database — with the
  policy version, chosen categories, method, language and region, and an
  anonymised hash instead of a raw IP address. Includes an admin viewer and a
  full CSV export of the entire log.
* WP Consent API compatible: registers as the consent provider and notifies other
  plugins (functional / preferences / statistics / marketing).
* Settings import/export (JSON), per-page/post exclusions and policy versioning
  with automatic re-consent when the version changes. No "accept on scroll".
* Accessibility: visible focus, ARIA roles, focus trap in the modal, large touch
  targets, RTL ready, reduced-motion support.
* Private, cookieless statistics — stored only in your own database and fully
  aggregated (no IP address, no visitor identifier, no personal data). See how
  many visitors accept, reject or open preferences, the accept rate per period,
  the average time to decision and the browser-language / device / OS mix. On by
  default; can be turned off in one click.
* Optional benchmark sharing (off by default): when you opt in, the plugin sends
  only aggregated weekly numbers and your site domain to the Lynbro portal so you
  can see how your accept rate compares to the average. No visitor data is sent.
* Built-in feedback form: send ideas, requests or bug reports to the developers
  straight from the admin (only when you click Send).
* Language on demand: request a translation for a locale that isn't bundled; it
  is stored in your uploads folder (update-safe) and activated automatically.
* Lightweight vanilla JavaScript (no jQuery), no front-end external requests
  beyond an optional, non-blocking statistics beacon to your own site.
* Truly multilingual and cache-safe: the initial banner language follows a
  cache-safe cascade (current page language of Polylang/WPML/TranslatePress →
  site language → English). Optional browser auto-detection and an in-banner
  language switcher pick the best language entirely in the visitor's browser,
  so full-page caches are never affected.
* Edit banner texts per language in the admin (title, description, buttons,
  category names and descriptions), with the priority your override → bundled
  translation → default. You can even add your own locale and texts.
* Fully translatable. Ships with 35 bundled languages — including all 24
  official EU languages (Danish, German, French, Spanish, Italian, Dutch,
  Polish, Swedish, Greek and more) — plus any other locale on demand and via
  translate.wordpress.org.
* Developer-friendly: filters and a JS API
  (`window.LynbroCookieConsent.getConsent() / .openSettings() / .onChange()`).

Every feature listed here is included for free, with no pageview limits and
nothing locked behind a paywall.

== Installation ==

= Install =

From your dashboard (recommended):

1. Go to **Plugins → Add New** and search for "Lynbro Cookie Consent".
2. Click **Install Now**, then **Activate**.

Or upload it manually:

1. Download the .zip and go to **Plugins → Add New → Upload Plugin**, choose the
   file and click **Install Now**, then **Activate**. (Or extract the folder to
   `/wp-content/plugins/lynbro-cookie-consent` and activate it from the Plugins screen.)

= It works out of the box =

On activation the banner goes live immediately with safe, GDPR-ready defaults — an
equally prominent "Reject all", all non-essential cookies denied until consent, and
Google Consent Mode v2 set to "denied". You do not have to configure anything to be
compliant. Everything below is optional fine-tuning under **Settings → Cookie Consent**.

= Recommended first steps =

1. **Categories** — review the consent categories (Necessary, Preferences, Statistics,
   Marketing) and edit their names/descriptions if you like.
2. **Region mode** — choose how consent behaves by region (EU/EEA opt-in, US opt-out
   / CCPA, rest-of-world notice), or keep the safe opt-in default.
3. **Block your scripts** — for trackers added by hand, mark them as
   `<script type="text/plain" data-lynbro-cc="statistics">…</script>` (or `marketing`);
   well-known trackers (GA4, GTM, Meta Pixel, Hotjar, Clarity, …) are auto-blocked.
4. **Google Consent Mode v2** — if you use Google Analytics/Ads/Tag Manager, leave it
   enabled; signals default to "denied" and update to the visitor's choice automatically.
5. **Design** — pick a layout (bottom/top bar, floating box, center modal), colours and
   the floating "Cookie settings" button, with a live device-switchable preview.
6. **Languages** — 35 languages ship in the box; enable browser auto-detection and the
   in-banner switcher, or edit texts per language (you can add your own locale too).

= Verify =

Open your site in a private window — the banner should appear. Choose an option, then
re-open it via the floating button or the `[lynbro_cookie_settings]` shortcut to change
it. Proof-of-consent records and cookieless statistics appear under
**Settings → Cookie Consent → Consent Log / Statistics**.

== Frequently Asked Questions ==

= Is it really completely free? =
Yes. The plugin handles the complete compliance flow — banner, categories,
script blocking, Google Consent Mode v2, geo-aware modes, analytics, the consent
log and translations — with nothing locked behind a paywall and no pageview
limits. There is nothing to buy.

= Does it call any external service? =
By default the front end makes no third-party requests: the visitor's choice is
kept in a first-party cookie, and the optional statistics beacon goes only to
your own site (same domain). Three features can contact the Lynbro portal, and
all three are opt-in actions you control: benchmark sharing (off by default),
the feedback form (only when you click Send) and "request a language" (only when
you click Request). Each sends aggregated data only — never a visitor IP or any
personal data. See the "External services" section below for full details.

= How do I block my analytics/marketing scripts until consent? =
Output them as `<script type="text/plain" data-lynbro-cc="statistics">...</script>`
(or `marketing`). They are activated automatically once the visitor grants that
category. A helper function `lynbro_cookie_consent_blocked_script()` is provided.

= Does it support Google Consent Mode v2? =
Yes, for free. Signals default to "denied" before consent and are updated to
match the visitor's choice.

= How does the banner choose its language? =
It uses a cache-safe cascade: the current page language (when Polylang, WPML or
TranslatePress is active), then your site language, then English. If you enable
"auto-detect visitor language", the banner additionally picks the best of your
offered languages from the visitor's browser — and that choice is made in the
browser, so it never breaks full-page caching. You can also enable an in-banner
language switcher. Texts can be overridden per language under Settings → Cookie
Consent → Languages, including adding your own locale.

= Does it block embedded videos and maps? =
Yes. With "Embed placeholders" enabled, iframes from YouTube, Vimeo, Google Maps,
social networks, reCAPTCHA and similar providers are blocked until the visitor
consents (or clicks "allow" on the placeholder). The detection is local; no
external request is made to identify or block an embed.

= Does it honor Global Privacy Control (GPC)? =
Yes. In opt-out (US/CCPA) mode, visitors whose browser sends a GPC signal are
automatically opted out of non-essential cookies and shown a visible
confirmation, as required by California from 2026.

= Where is the consent log stored? =
In a dedicated table in your own WordPress database. No raw IP address is stored;
only a salted, anonymised hash is kept for de-duplication. You can view recent
records and export the complete log as CSV from Settings → Cookie Consent →
Consent Log.

= Is it compatible with caching plugins? =
Yes. Consent state lives client-side (first-party cookie + JS), so it is never
baked into cached HTML, and the consent core is automatically excluded from
delay/defer/minify/combine of WP Rocket, LiteSpeed, SiteGround Optimizer,
Autoptimize and Cloudflare Rocket Loader.

== Screenshots ==

1. The consent banner with an equally prominent "Reject all" button — works out of the box with GDPR-ready defaults, no dark patterns.
2. Granular preferences: per-category toggles (Necessary, Preferences, Statistics, Marketing) with clear descriptions.
3. Truly multilingual — automatic browser-language detection and an optional in-banner language switcher, with 35 bundled languages.
4. Admin settings with a live, device-switchable preview (desktop / tablet / mobile) and a one-line WCAG-AA accessibility check.
5. Design tab: ready-made templates, layout (bottom bar, top, floating box, center modal), colours and the floating button — all without code.
6. Private, cookieless statistics in your own database: accept rate per period, devices, operating systems and browser languages — with no IP address and no personal data.

== External Services ==

By default this plugin runs locally. The banner, script blocking, automatic
tracker detection, embed placeholders, Google Consent Mode v2 signalling,
geo-aware consent mode, Global Privacy Control handling, the local consent log
and the local statistics all work on your own server and in the visitor's
browser, with no account and no third-party API.

The local statistics use a small, non-blocking beacon that is sent **only to your
own WordPress site** (the same domain) to count aggregated events. It does not
contact any third party and stores no IP address or personal data.

Three optional features can contact the Lynbro portal at
https://plugins.lynbro.dk. None of them runs automatically without your action,
and none of them sends a visitor IP or any personal data:

1. Benchmark sharing (telemetry) — **off by default**. When you turn it on, a
   weekly background job sends aggregated counters for the last 7 days (banner
   shown / accept / reject / manage-open / language-switch totals, the average
   time-to-decision, and the browser-language / device / OS distributions) plus
   your site's domain to `https://plugins.lynbro.dk/v1/telemetry`. The portal
   returns an anonymous benchmark so you can compare your accept rate to the
   average. Sent only while the option is enabled.

2. Feedback — sent **only when you submit the feedback form** in the admin. It
   posts your message, the chosen category, the plugin version, your site domain
   and (optionally) the email you type, to `https://plugins.lynbro.dk/v1/feedback`.

3. Language on demand — sent **only when you click "Request this language"**. It
   posts the plugin slug, the plugin version and the requested locale code to
   `https://plugins.lynbro.dk/v1/i18n/translate`, and stores the returned
   translation file in your site's uploads folder. No visitor data is involved.

Your use of these optional portal features is governed by the Lynbro Privacy
Policy and Terms at https://plugins.lynbro.dk.

Note: any third-party scripts or embeds *you* add to your own site (for example
Google Analytics, Meta Pixel or a YouTube video) continue to contact their own
providers — but only after the visitor grants the matching consent category. This
plugin's role is to gate them, not to add them.

== Changelog ==

= 0.4.5 =
* Fix: on mobile, opening "Manage preferences" could make the dialog overflow
  horizontally (the categories view wrapped into a second column, forcing
  sideways scrolling to reach the buttons). The dialog now stays a single column
  and scrolls vertically instead.

= 0.4.4 =
* Fix: on mobile the bar layout could stretch to most of the screen height with a
  large empty gap between the text and the buttons. The banner body now sizes to
  its content, the content packs to the top, and the bar never exceeds 85% of the
  viewport. Added slightly smaller mobile font sizes for the title and text.

= 0.4.3 =
* Compliance: tracker-blocking no longer emits literal script tags from PHP — the
  enqueued-script filter now modifies the existing tag in place, and the manual
  helper builds the inert placeholder without a literal script tag.

= 0.4.2 =
* Admin: hide the Save button on tabs without editable settings.

= 0.4.1 =
* Compliance: the consent-log CSV export now exports the complete log (the
  previous row limit was removed); large logs are streamed in batches.
* Compliance: removed the "Additional CSS" field — use the WordPress Customizer's
  built-in Additional CSS instead.
* Compliance: refactored script handling — the Google Consent Mode v2 default
  stub is now enqueued via wp_add_inline_script, and the optional full-page
  output-buffer scan was removed (enqueued-tracker blocking is unchanged).
* Hardening: the consent-gated script helper now guards inline bodies against a
  </script> breakout.

= 0.4.0 =
* New: private, cookieless, aggregated statistics stored in your own database —
  accept/reject/manage-open counts, accept rate per period (today, yesterday, 7,
  30 and 365 days), average time-to-decision and browser-language / device / OS
  distributions. No IP address and no personal data. Recorded via a non-blocking
  same-site beacon. On by default; one click to disable.
* New: optional benchmark sharing (off by default) — opt in to send aggregated
  weekly numbers and your site domain to the Lynbro portal and see how your
  accept rate compares to the average.
* New: in-admin feedback form to send ideas, requests and bug reports (opt-in).
* New: "request a language" — fetch a translation for a non-bundled locale; it is
  stored in your uploads folder (update-safe) and activated automatically.
* New: a "Settings" link in the plugins list, a one-time redirect to the settings
  after activation, and a single, gentle, dismiss-forever review reminder after a
  week (no nags, no pressure).
* Improved: Danish (da_DK) translation updated to cover all new strings.

= 0.3.0 =
* New: cache-safe browser language auto-detection — the banner can show in the
  visitor's browser language (chosen client-side, so caching is unaffected).
* New: optional in-banner language switcher with a remembered choice.
* New: per-language text editor in the admin (title, description, buttons,
  category names/descriptions), with priority override → translation → default,
  plus the ability to add your own locale.
* New: "About" tab with author info, useful links and a version/updates note.
* New: quick-start status block confirming the banner is live with the key
  toggles at a glance.
* Improved: removed the explicit textdomain loader; bundled translations now
  load just-in-time (cleaner Plugin Check, no behaviour change).
* Improved: Danish (da_DK) translation updated to cover all new strings.

= 0.2.0 =
* New: embed/iframe consent placeholders (YouTube, Vimeo, Google Maps, social
  embeds, reCAPTCHA and more) with "allow" and "always allow this service".
* New: Global Privacy Control (GPC) support with a visible opt-out confirmation.
* New: explicit cache compatibility (auto-exclusion from WP Rocket, LiteSpeed,
  SiteGround Optimizer, Autoptimize, Cloudflare) and zero layout shift.
* New: light/dark/auto theme with separate dark color set and custom CSS.
* New: floating "Cookie settings" re-open button (toggle, corner, label, icon).
* New: WP Consent API compatibility (registers as the consent provider).
* New: live admin preview with a WCAG-AA contrast validator.
* New: center-modal layout with a focus trap, and a box-corner choice.
* New: local consent log (proof of consent) with admin viewer and CSV export.
* New: automatic blocking of well-known trackers by a built-in catalog.
* New: settings import/export (JSON), per-page/post exclusions, policy versioning
  with automatic re-consent.
* New: CCPA/CPRA "Do Not Sell or Share" link in opt-out mode.

= 0.1.0 =
* Initial release: banner, granular categories, script blocking, Google Consent
  Mode v2, geo-aware consent modes, shortcode, accessibility, English + Danish.

== Upgrade Notice ==

= 0.4.3 =
Compliance update for the WordPress.org review: tracker blocking no longer emits
literal script tags from PHP. No change to behaviour.

= 0.4.2 =
Minor admin polish: the Save button no longer appears on tabs that have no
editable settings.

= 0.4.1 =
Compliance and hardening update: full consent-log CSV export, the Additional CSS
field was removed (use the Customizer), and script handling was refactored.

= 0.4.0 =
Adds private cookieless statistics, optional benchmark sharing, an in-admin
feedback form and language-on-demand. All portal features are opt-in.

= 0.3.0 =
Adds cache-safe browser language auto-detection, an in-banner language switcher,
a per-language text editor and an About panel.

= 0.2.0 =
Adds embed placeholders, Global Privacy Control, light/dark/auto theme, WP Consent
API support, a local consent log, automatic tracker blocking and import/export.

= 0.1.0 =
Initial release.

== Privacy ==

The plugin stores data only in your WordPress database (your settings and, if
enabled, the local consent log and the local aggregated statistics) and in
first-party cookies on the visitor's browser (their consent choice and, if used,
the chosen banner language). The consent log never stores a raw IP address — only
a salted, anonymised hash for de-duplication. The statistics are fully aggregated
and contain no IP address or visitor identifier; they are recorded via a
non-blocking beacon to your own site only.

The optional portal features — benchmark sharing (telemetry), the feedback form
and language-on-demand — are opt-in and described in the "External services"
section above. They send aggregated data and your site domain only, never a
visitor IP or personal data, to https://plugins.lynbro.dk. See our Privacy Policy
and Terms there. GDPR/DK compliant.
