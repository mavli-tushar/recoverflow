# RecoverFlow

RecoverFlow is the WooCommerce Product View Reminder Lite plugin. It tracks product views for logged-in customers and can send product or optional cart reminders when a customer is eligible. It does not identify anonymous visitors, and a product view is never consent.

## Installation and setup

1. Activate WooCommerce and RecoverFlow.
2. Open **RecoverFlow > Settings**. Configure the product tracking delay, reminder waiting period, cron frequency, and optional cart reminders.
3. Complete the RecoverFlow storage setup when prompted. New storage is additive. An upgrade from schema 1.3 adds the nullable cart-add timestamp and the indexes used for bounded cleanup and privacy lookups; existing rows and purchase-index progress are preserved.
4. Use **Build purchase lookup** to index existing WooCommerce orders in bounded batches. Sending stays blocked until this is complete. The indexer uses WooCommerce CRUD APIs and does not change orders.
5. Choose an existing consent adapter or enable the optional RecoverFlow consent checkboxes. Marketing eligibility remains default-deny.

The **Tracking delay** controls how long a logged-in customer must remain on a product page before a view is recorded. The separate **Reminder waiting period** defaults to 24 hours. For cart reminders, the wait starts at the cart-add time; legacy cart records without that timestamp fall back to their existing view time. WP-Cron is traffic-driven unless the site runs WordPress cron independently.

## RecoverFlow admin pages

- **Dashboard** shows stored views and age-qualified reminder rows.
- **Customer Reminders** provides search, current consent and eligibility explanations, scheduled dates, purchase status, and confirmed manual sends. Manual sends skip only the waiting period. Consent, opt-outs, product validity, current purchase state, duplicate and retry checks, and frequency limits still apply.
- **Reminder History** shows manual and automatic attempts, results, failures, retries, and blocked actions. “wp_mail accepted” means WordPress accepted the message for sending; it does not confirm inbox delivery.
- **Analytics** reports stored views and attempt records for the selected date range. The eligibility count is a bounded scan. RecoverFlow does not invent revenue or conversion estimates.
- **Email Templates** supports subject, heading, sender, product-view and cart bodies, CTA text, escaped placeholders, synthetic previews, and local-only test mail to a `.test` address.
- **Consent Management** summarizes the latest ledger status, source, channel, timestamp, and adapter registration. It never offers an administrator opt-in action. A verified-revocation action records opt-outs only.
- **System Status** distinguishes a scheduled cron event from a completed RecoverFlow callback and reports storage, purchase-index, HPOS, failure, and mail configuration diagnostics.
- **Settings** contains reminder, consent collection, and privacy retention controls.
- **Help** gives administrators a step-by-step guide to every page, reminder timing, consent, manual sends, email results, privacy, and troubleshooting.

## Consent and eligibility

Product views, account registration, and checkout alone do not grant consent. RecoverFlow does not enable marketing reminders globally. The `wc_pvr_customer_is_eligible_for_marketing` filter receives the current eligibility value (false by default), user ID, sanitized email, and view row. An adapter should call the store’s actual consent or communication-preference API, for example:

```php
add_filter(
    'wc_pvr_customer_is_eligible_for_marketing',
    function ( $eligible, $user_id, $email, $view ) {
        if ( $eligible ) {
            return true; // Preserve a previously registered, trusted adapter.
        }

        // Replace this with your consent provider's documented API.
        return My_Store_Consent::has_active_email_marketing_opt_in( $user_id, $email );
    },
    20,
    4
);

add_filter(
    'wc_pvr_customer_has_marketing_opted_out',
    function ( $opted_out, $user_id, $email, $view ) {
        // Opt-outs and revoked consent must stay authoritative.
        return $opted_out || My_Store_Consent::has_active_email_marketing_opt_out( $user_id, $email );
    },
    20,
    4
);
```

`My_Store_Consent` above is illustrative, not a WooCommerce API. WooCommerce does not provide a universal marketing-consent field. Do not infer permission from a view, registration, or checkout. Keep the eligibility filter for developer integrations, and make the provider reflect opt-outs and consent revocation in real time. A RecoverFlow ledger opt-out overrides positive adapter results.

The optional built-in consent ledger displays unchecked choices at registration, classic checkout, and Checkout Blocks, and lets logged-in customers manage preferences at **My Account > Email preferences**. The choice is optional and does not gate registration or purchase. Unsubscribe links require confirmation, are single-use, and expire after 30 days. Existing customers are never opted in automatically. The ledger stores a keyed email hash instead of a second raw email copy.

## Purchase suppression and retries

RecoverFlow maintains a product-to-order lookup using WooCommerce lifecycle hooks and HPOS-compatible CRUD APIs. It checks paid processing and completed orders, logged-in customer IDs, guest billing emails, and product variations. Before a reminder is sent, matching indexed orders are reloaded and their current paid status and line items are checked.

The service enforces at most one accepted reminder per email address in seven days, a maximum of three attempts in 24 hours, and up to three attempts per reminder with 5-minute and 60-minute failure backoff. MySQL/MariaDB advisory locks serialize concurrent attempts for the same address while the lock is held. These safeguards reduce duplicates; they do not provide exactly-once email delivery. If the mailer runs but the result cannot be saved, the pending history row remains outcome-unknown and blocks that email for 24 hours. A later retry can still duplicate an already accepted message.

## Privacy and retention

RecoverFlow registers a WordPress personal-data exporter and eraser. Export includes RecoverFlow views, consent choices, reminder attempts, and purchase lookup references. Erasure removes RecoverFlow view, history, and purchase-index references; it never deletes or modifies WooCommerce orders.

By default, erasure retains one keyed email hash as an opt-out suppression record. This prevents a separate positive adapter from re-enabling reminders for an erased identity. Store administrators can disable that retention in **Settings > Privacy and retention** when required by their privacy obligations. The hash remains pseudonymous personal data and should be handled under the store’s privacy policy.

View, reminder-history, and consent-history retention defaults to `0` (retain until manual erasure). Reminder history may be retained indefinitely (`0`) or for at least seven days so the weekly frequency limit remains effective. Automatic cleanup runs daily and deletes at most 250 expired rows per data set per run. Historical consent rows are removed only when a newer choice exists, so the latest opt-in or opt-out is retained. Expired unsubscribe-token data is cleared in bounded batches. Purchase lookup rows are retained while WooCommerce orders remain available for suppression checks.

RecoverFlow adds its data practices to the WordPress privacy-policy guide. Review the retention and suppression choices against your store’s applicable requirements.

## Troubleshooting

- **Reminders are paused:** check **System Status**. Complete storage setup and the purchase lookup before sending.
- **No customers are eligible:** confirm a current explicit opt-in or a trusted store consent adapter. A registered adapter can still deny all customers. Review the customer-specific reason in **Customer Reminders** and the ledger in **Consent Management**.
- **The cron event is scheduled but nothing ran:** scheduled time is not proof of execution. Check the last completed callback time and configure traffic-driven WP-Cron or a system cron.
- **wp_mail accepted but no message appears:** check the configured mail transport. Acceptance does not confirm delivery or inbox placement.
- **Local email checks:** send only to synthetic `.test` addresses and inspect LocalWP Mailpit. Do not run tests against real customer addresses.

## Automated and LocalWP checks

Dependency-free smoke tests:

```text
php tests/consent-frontend-smoke.php
php tests/reminder-service-smoke.php
php tests/tracker-smoke.php
php tests/privacy-smoke.php
```

The LocalWP-only integration test is guarded against non-local environments. Run it with the LocalWP PHP executable and site `php.ini`:

```text
<LocalWP PHP executable> -c <LocalWP php.ini> tests/localwp-final-integration.php
```

It creates unique synthetic `.test` customers, products, and unpaid WooCommerce orders, limits cron eligibility to its own synthetic address, checks accepted mail in LocalWP Mailpit, exercises the privacy exporter/eraser, and cleans up its temporary records. Browser-level checkout, My Account, and unsubscribe checks still require the manual steps documented in `DEVELOPMENT_AUDIT.md`.

## Scope and known limits

RecoverFlow does not provide guest view tracking, SMS, WhatsApp, Twilio, paid APIs, confirmed inbox delivery, or exactly-once sending. The purchase lookup must be complete and relies on WooCommerce hooks plus bounded recent-order checks. Test results and remaining browser checks are listed in `DEVELOPMENT_AUDIT.md`.
