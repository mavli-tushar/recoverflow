# RecoverFlow Development Audit

Last updated: 2026-11-21

## Implemented state

RecoverFlow remains on its existing tracker, reminder service, settings class, custom view table, and WooCommerce CRUD order index. Plugin version is 2.1; consent/storage schema version is 1.4. This UI refinement adds no database migration.

- Product tracking remains limited to logged-in customers. Frontend tracking delay and reminder waiting period are separate. The reminder wait defaults to 24 hours.
- Cart rows now have a nullable `cart_added_at` value. The waiting period starts from cart addition; older rows without the value use their legacy `view_time`.
- Marketing eligibility is default-deny. Product views, registration, or checkout alone are never consent. A current RecoverFlow opt-out overrides positive eligibility filters.
- The developer eligibility filter keeps its existing four arguments: current eligibility, user ID, sanitized email, and view. The opt-out filter remains available to add external vetoes.
- Consent Management lists the latest choice per keyed email identity, status, source, channel, update time, masked customer reference, and an explanation. It reports adapter registration without claiming that a registered adapter allows any particular customer. Administrators can record a verified opt-out only; there is no silent opt-in action.
- System Status reports software versions, HPOS state, schema/ledger and purchase-index state, pending age-qualified rows, recent failures, mail configuration, retention cleanup, and cron scheduling separately from the last completed RecoverFlow callback.
- Added a Help page under RecoverFlow with direct links and plain-language setup, feature, consent, email, privacy, and troubleshooting guidance for store administrators.
- Version 2.1 refines the admin experience without changing reminder eligibility, consent, purchase suppression, or mail behavior. Dashboard charts, KPIs, queue status, and recent attempts are easier to scan; reminders have compact customer/product cells, status badges, search and record filters, pagination, and expandable action explanations; History and Analytics display badge-based outcomes and daily trends; integration diagnostics are collapsed until requested.
- Fixed duplicate email text in the Customer Reminders row when the WordPress display name equals the email address. Admin query filters now reject array-shaped values safely. HTML time metadata uses the WordPress site timezone.
- Admin CSS selectors are scoped to RecoverFlow screens, with visible keyboard focus, responsive cards/forms, and horizontally scrollable wide tables to avoid page-level overflow on narrow screens.
- WordPress privacy exporter and eraser are registered. Erasure removes RecoverFlow view/history/purchase-index data and leaves WooCommerce orders untouched. By default, one keyed opt-out suppression hash is retained; administrators can disable it in Privacy and retention settings.
- View, history, and consent-history retention are configurable. Defaults are 0 (retain until manual erasure). History retention accepts either 0 or at least seven days, preserving the weekly frequency cap. Cleanup runs daily, deletes at most 250 selected IDs per data set per invocation, keeps the latest consent status, and clears expired token fields in bounded batches.
- Added a privacy-policy guide section explaining RecoverFlow data and erasure behavior.
- Reminder attempts reserve a pending history row before mail. Failed and accepted results are distinct. "Accepted" means `wp_mail()` returned success, not confirmed inbox delivery.
- Per-email advisory locks, retry backoff, duplicate checks, attempt caps, and frequency limits reduce duplicate sends but do not provide exactly-once delivery.
- Purchase lookup uses WooCommerce CRUD APIs and lifecycle hooks, is HPOS compatible, and matches paid processing/completed orders, guest billing email, customer IDs, and variations. No direct order-table SQL is used.

## Schema migration and data safety

The version 1.4 migration is additive. It adds one nullable `cart_added_at` column and indexes for view retention/identity, consent-history/token expiry, and user-linked attempts. Existing view rows receive `NULL` for the new timestamp and retain their old behavior through the fallback to `view_time`. The migration does not delete or rewrite customer, consent, view, or order data and does not reset purchase-index progress.

The migration uses the existing `maybe_install()` schema path and runs for an existing 1.2 or 1.3 installation on an admin request. The LocalWP integration test forced the installed schema through the 1.3-to-1.4 path, verified the new column and indexes, and confirmed the purchase-backfill completion/page options were unchanged. The local database finished at schema 1.4 with the pre-test lookup state preserved.

The `customer_email` index uses a 100-character prefix, within older MySQL/MariaDB index-size limits. View-table indexes are added with checked, idempotent `ALTER TABLE` statements because LocalWP WordPress 7.1's `dbDelta()` emitted invalid blank-index SQL for that existing custom table. The migration detects indexes before declaring itself ready.

## LocalWP environment and runtime verification

- Site root: `C:\Users\DELL\Local Sites\password-protected-store-for-woocommerce\app\public`.
- Runtime: WordPress 7.1.4, WooCommerce 10.5.3, PHP 8.2.27. The LocalWP environment reports `local`; the site bootstrap connected to the LocalWP database.
- LocalWP PHP and its site `php.ini` were used for integration tests. The ini prints a missing optional `php_imagick.dll` startup warning; it did not prevent WordPress bootstrap, database access, WooCommerce CRUD, or mail capture.
- Mailpit was reachable on the LocalWP API at `127.0.0.1:10018`. Two unique `.test` messages from the latest synthetic integration run were found in Mailpit: one manual send and one cron send. No non-synthetic recipient was enabled by the test eligibility filter.
- `tests/localwp-final-integration.php` passed: additive migration/index/backfill assertions, Consent Management, System Status, and Help page server-side rendering, privacy exporter/eraser registration, temporary synthetic users/products/unpaid WooCommerce orders/consent/views, one accepted manual send, duplicate block, one due automatic cron send, accepted history, cron completion time, privacy export including purchase lookup references, erasure with opt-out suppression, proof the WooCommerce order remained unchanged, and daily cleanup schedule. Post-cleanup assertions verified its synthetic WordPress users, orders, products, views, consent, history, and purchase references were removed.
- The version 2.1 LocalWP integration run additionally rendered the redesigned Dashboard, Analytics, Reminder History, and Customer Reminders pages against synthetic rows. It verified reminder filters, safe handling of array-valued query input, a single display of the synthetic customer's email, and the eligible manual-send action; after send it verified manual/accepted badges and expandable attempt details. The run's manual and automatic synthetic emails were accepted by `wp_mail()` and the test cleaned up both synthetic customers, products, orders, and RecoverFlow records.
- The LocalWP test is CLI/server-side integration, not browser interaction. The local order backfill option was not changed by the test. WP-Cron test eligibility was restricted to its unique `.test` address.
- Earlier LocalWP service runs verified `wp_mail()` false handling, purchase suppression for logged-in and guest billing-email matches, variation matching, manual retry/duplicate handling, and order CRUD indexing. Those prior cases were not repeated in the final integration script.
- The tests did not execute retention deletion against the LocalWP store's existing rows. The standalone privacy smoke test checks registration, invalid-identity handling, scheduling, and the 250-ID batch bound using a synthetic database double.

## Automated checks

The following standalone tests passed during this development pass:

- `php tests/consent-frontend-smoke.php`
- `php tests/reminder-service-smoke.php` — includes 24-hour view and cart waiting boundaries, consent default-deny and opt-out override, product validation, cart settings, send outcomes, duplicates, retries, frequency caps, and logged-in/guest/variation purchase cases.
- `php tests/tracker-smoke.php` — includes logged-in tracking, invalid/deleted/unpublished product IDs, guest rejection, and the cart-add timestamp.
- `php tests/privacy-smoke.php` — includes privacy callback registration, invalid email handling, cleanup scheduling, and 250-row bounded deletion statements.
- `php tests/localwp-final-integration.php` — LocalWP-only synthetic integration test described above.

- PHP syntax lint passed for all 13 plugin PHP files.
- `node --check assets/js/admin.js` passed.
- `git diff --check` passed; Git printed only working-copy LF-to-CRLF normalization notices for two PHP files.

## Browser verification still required

No browser automation or computer-use tool is available in this session. The new admin pages were rendered through the real LocalWP WordPress bootstrap, but the registration/checkout/account/unsubscribe UI has not been clicked through in a browser during this pass.

Manual LocalWP steps using synthetic identities only:

1. Open `http://password-protected-store-for-woocommerce.local/wp-admin/` (or the site domain shown by LocalWP) and sign in as an administrator. Open **RecoverFlow > System Status** and confirm schema 1.4, purchase lookup state, scheduled cron, last completed callback, and retention cleanup status.
2. In **Settings**, enable built-in consent and the registration/checkout checkboxes for this test. Use a new synthetic email under `example.test`; do not use an existing customer address.
3. Register one synthetic account with the checkbox unchecked and verify it is not opted in. Repeat with another synthetic account and explicitly check it; verify its ledger source/channel/status under **Consent Management**.
4. On classic checkout and then a Checkout Blocks checkout page, place a synthetic test order with the unchecked box and another with the explicitly checked box. Confirm the checked choice is recorded against the order billing email. Do not place an order using a real customer identity.
5. Sign in as the checked synthetic customer. Open **My Account > Email preferences**, toggle on and save, reload to verify; toggle off and save, reload, and confirm the ledger shows opted out and the Customer Reminders page blocks eligibility.
6. In **Email Templates**, use the synthetic preview and LocalWP test-email control with an `.test` address. Open LocalWP Mailpit at `http://127.0.0.1:10018`, follow the unsubscribe URL, confirm the POST action, then verify Consent Management shows the latest opt-out.
7. Create a published synthetic product and visit it while signed in as a synthetic customer. Verify it appears in Customer Reminders with the separate scheduled date. Use the confirmed manual-send action and verify the accepted result/history in Mailpit and Reminder History. Repeat on a second synthetic account to check cron after aging its view in a disposable LocalWP test copy.
8. Create a synthetic paid processing/completed order containing the same simple or variable product for a synthetic account, then reload Customer Reminders and verify purchase suppression. For a guest suppression case, use a guest order with the exact synthetic billing email. Remove only those test orders/products/accounts after verification.
9. Save harmless settings (for example, tracking delay and reminder wait) and reload Settings. Restore the original setting values after testing. Keep retention values at 0 on a store with any non-test rows; test destructive cleanup only on a disposable LocalWP clone.

## Remaining limitations

- Browser behavior for registration, classic checkout, Checkout Blocks, My Account saving/revocation, unsubscribe confirmation, settings POSTs, and responsive rendering remains unverified here.
- The selected LocalWP WooCommerce order lookup was already marked complete. Historical backfill across an actual store's full order set was not run or re-run in this phase; it can read existing WooCommerce orders in 100-order batches when an administrator starts it.
- Retention cleanup SQL is bounded and covered with a fake database, but actual deletion was intentionally not run against existing LocalWP rows. Defaults are disabled (0).
- Advisory locks do not guarantee exactly-once email delivery. If WordPress accepts mail and RecoverFlow cannot save that result, status remains unknown; a retry after the safety window may duplicate it.
- A completed WP-Cron callback timestamp does not mean reminders were sent; rows may all have been blocked or mail may have failed. Mail acceptance does not prove delivery.
- Purchase suppression relies on completed indexing and WooCommerce lifecycle hooks. The bounded recent-order lookup catches newly placed orders; if a hook is missed long after indexing, an old order might not be in that recent fallback.
- The LocalWP PHP ini continues to report the optional Imagick extension warning. It is unrelated to RecoverFlow.

## Next recommended work

1. Complete the browser steps above using synthetic identities and Mailpit before production use.
2. Validate the checkout registration fields against the store's active classic/Blocks checkout templates and supported WooCommerce versions.
3. Exercise the full order backfill and processing/completed/refunded/cancelled/variation transitions on a disposable LocalWP clone.
4. Test concurrent manual/cron requests on a supported MySQL/MariaDB environment and review advisory-lock behavior on multi-server hosts.
5. Review suppression-hash retention and configured retention periods with the store's privacy/legal requirements.

Version 2.1 adds no schema migration and has not been deployed to production. LocalWP Mailpit contained both expected synthetic `.test` messages from the latest integration run. Browser click-through and responsive screenshots remain unverified.
