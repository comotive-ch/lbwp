# Payrexx Webhook Setup

The subscription feature (`LBWP\Aboon\Subscription`) needs a webhook configured in the Payrexx
(white-label) dashboard so that renewals, failed payments and cancellations initiated by Payrexx
itself are reflected in this shop.

## Steps

1. Log in to the Payrexx administration for the configured instance/platform.
2. Go to **Account → Webhooks** (or **Integrations → Webhooks**, depending on the platform
   version).
3. Add a new webhook:
   - **URL:** `https://<shop-domain>/wp-json/lbwp-subscription/v1/webhook`
   - **Method:** POST
   - **Events:** subscribe to `Transaction` (created/updated) and `Subscription`
     (updated/cancelled) events. The exact event checkboxes are not documented in the bundled
     SDK — verify the available options against the live dashboard.
4. Save, and trigger a test event from the dashboard (if available) to confirm the endpoint
   responds with HTTP 200.

## Security note

The bundled Payrexx SDK (`resources/libraries/payrexx`) has no webhook signature/HMAC
verification. The webhook endpoint is therefore intentionally public
(`permission_callback => '__return_true'`) and never trusts the posted payload's status: it only
reads an entity id and re-fetches the authoritative record from Payrexx using the shop's own
stored API credentials before updating anything (see `Webhook\WebhookController`). This means the
endpoint has no destructive capability even if the URL becomes known to a third party.

## Daily safety net

Independent of the webhook, `Cron\SyncCron` re-syncs every active subscription's status from
Payrexx once a day (hooked to the framework's existing `cron_daily` tick), in case a webhook
delivery was ever missed.

## Open items to verify against the live Payrexx account before go-live

- Exact status vocabulary Payrexx uses for `Subscription`/`Gateway` responses (the SDK only
  defines status constants for `Transaction`). `Helper::mapPayrexxStatus()` currently maps a
  best-guess set of strings and falls back to "active" for anything unrecognised, so an unmapped
  terminal-failure status will not currently trigger the admin notification — update the mapping
  once the real values are known.
- Whether the "add/update payment method" link (`SubscriptionApi::buildPaymentMethodUpdateLink()`)
  should use a different Payrexx page type than the current nominal pre-authorization Gateway.
- Whether a one-off charge against an existing PSP id (used for upgrade proration in
  `Upgrade\UpgradeHandler`) requires a different request shape than a plain `Gateway` with `psp`
  set.
