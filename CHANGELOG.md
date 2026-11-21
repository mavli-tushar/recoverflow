# Changelog

## 2.1 - Released 2026-11-21 (Admin UI/UX refinement)

- Redesigned the RecoverFlow Dashboard with compact 30-day KPIs, daily views and reminder outcome charts, queue readiness, recent activity, and direct navigation to key tasks.
- Improved Customer Reminders with responsive table scrolling, email/customer-ID search, record filters, customer initials, product thumbnails, concise status badges, expandable eligibility explanations, and disabled send actions with blocking reasons.
- Fixed repeated customer email display when a WordPress display name is the same as its email address.
- Improved Reminder History badges and expandable attempt details, and replaced the Analytics activity table with accessible daily outcome charts including unknown outcomes.
- Clarified consent adapter diagnostics, settings timing labels, and headers/descriptions across plugin admin pages. Scoped the plugin's admin styles and added keyboard focus and mobile layouts.
- Hardened admin filter inputs against array-valued query parameters and fixed site-timezone metadata on date/time elements.
- Added LocalWP assertions for Dashboard, Analytics, History, Reminder list filters, and single-email rendering.
- Verification: PHP syntax checks, JavaScript syntax check, four dependency-free smoke tests, and the LocalWP synthetic integration test. Mailpit captured both unique synthetic `.test` messages from manual and cron sends. Admin page rendering and send/cron flows were tested on the server; browser click-through and viewport screenshots remain manual checks.

## 2.0 - Released 2026-11-21

- Added a RecoverFlow Help page with plain-language guidance and direct links for each admin feature, setup, consent, timing, manual reminders, analytics, email templates/history, privacy, and troubleshooting.
- Set the plugin header and asset version to 2.0 for this release.
- Added LocalWP integration coverage for Help page rendering.

### Consent, privacy, and reliability foundation

- Added Consent Management with latest-choice summaries, masked customer references, source/channel/date, adapter registration status, default-deny explanations, and verified opt-out recording only.
- Added System Status for WordPress/PHP/WooCommerce/HPOS, schema and order-index state, scheduled versus completed cron status, pending due rows, recent failures, cleanup, and mail configuration diagnostics.
- Registered WordPress privacy exporter and eraser callbacks, added privacy-policy guide content, and added configurable bounded retention for views, reminder history, and historical consent choices.
- Added daily cleanup for expired unsubscribe-token data. Latest consent choices and purchase lookup records are retained.
- Added an optional cart-add timestamp. Cart reminders now wait the configured period from cart addition; legacy rows with no cart timestamp fall back to their existing view timestamp.
- Added additive schema version 1.4 indexes and nullable cart timestamp migration. Existing view/consent/order data and purchase-backfill progress are preserved.
- Added dependency-free privacy cleanup tests and a LocalWP-only synthetic integration test for migration, admin rendering, manual send, cron processing, Mailpit acceptance, privacy export/erase, WooCommerce CRUD order preservation, cleanup scheduling, and post-run removal of synthetic users, orders, products, and plugin rows.
- Updated README, audit results, privacy setup, retention constraints, and LocalWP manual browser verification steps.

### 2.0 verification

- LocalWP ran WordPress 7.1.4, WooCommerce 10.5.3, and PHP 8.2.27. Synthetic manual and cron reminders were accepted by `wp_mail()` and both unique `.test` messages were confirmed in Mailpit.
- LocalWP schema migration to 1.4 preserved the pre-test purchase-backfill state. Synthetic export and erasure removed view/history/purchase references and retained only the configured hash-only opt-out suppression.
- `tests/privacy-smoke.php`, `tests/consent-frontend-smoke.php`, `tests/reminder-service-smoke.php`, and `tests/tracker-smoke.php` passed.
- Browser interaction for registration, classic/Blocks checkout, My Account, unsubscribe, and settings forms was not automated; see `DEVELOPMENT_AUDIT.md` for manual steps.

## 1.3.0 - Core manual reminders and templates

- Completed manual reminder sending from Customer Reminders with administrator capability, nonce, confirmation, consent, opt-out, purchase, duplicate, retry, and frequency checks.
- Added Reminder History with date, status, trigger, and search filters; results distinguish accepted by wp_mail(), failed, blocked, and outcome unknown.
- Added a pre-send history reservation. If the worker stops before saving an outcome, the pending row is visible and blocks reminders for that customer email across products for 24 hours.
- Added bounded 5-minute and 60-minute failure retry backoff, a three-attempt per-reminder limit, and customer frequency limits.
- Added Analytics for stored views, due candidates, bounded eligibility checks, accepted/failed attempts, purchase suppressions, rankings, and daily activity. No revenue or conversion estimate is inferred.
- Added Email Templates controls for subject, heading, sender, call-to-action, product-view body, cart body, synthetic previews for both templates, and local-only .test email.
- Improved responsive admin tables/cards and clarified that wp_mail acceptance is not confirmed delivery.
- Removed duplicate email-template controls from General Settings and linked to the dedicated Email Templates page.
- Rechecked purchase suppression through WooCommerce CRUD APIs, including paid processing/completed orders, guest billing-email matches, and variation-specific matching. No direct WooCommerce order-table queries are used.
- Made a RecoverFlow consent-ledger opt-out authoritative even if an external opt-out filter returns false.
- Added dependency-free synthetic tracker, consent, and reminder-service smoke tests.
- Verified LocalWP PHP/DB bootstrap, admin callback rendering, manual accepted/failed/duplicate behavior, cron queue processing, and Mailpit capture with synthetic data.
- Updated README and DEVELOPMENT_AUDIT with test results and current limitations.

### Database notes

RecoverFlow schema version 1.2 added consent, email history, and order-product tables. The migration was additive and preserved existing customer/view records. No additional migration was needed for pending outcomes; they fit the existing history result column. The local order index backfill remains an explicit administrator action and must finish before reminders are enabled.

### Not included

No WhatsApp, SMS, Twilio, paid API, guest view tracking, or production deployment is included.

## 1.0 - Initial GitHub release

- Published the original WooCommerce Product View Reminder Lite MVP before the RecoverFlow 2.0 upgrade.
