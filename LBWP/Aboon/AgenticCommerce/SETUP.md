# Agentic Commerce Setup (OpenAI ACP + Google UCP)

The `LBWP\Aboon\AgenticCommerce` library and the three `LBWP\Aboon\Component\AgenticCommerce*`
components let an AI shopping surface (ChatGPT via the OpenAI **Agentic Commerce Protocol**, Google
AI surfaces via the **Universal Commerce Protocol**) build a cart, price it and place a real
WooCommerce order. The shop stays merchant of record. Everything lives in the LBWP framework plugin
and is configured per client through ACF options, so no theme code is needed beyond the standard
`lbwp-standard-03` bootstrap.

Design spec: `wp-content/themes/lbwp-standard-03/assets/acp-ucp.md`.

## 1. Architecture in one minute

```
Component/AgenticCommerce.php        shared settings page, product fields, key generation, order meta box
Component/AgenticCommerceAcp.php     OpenAI ACP: /wp-json/acp/v1, order webhooks, TSV product feed
Component/AgenticCommerceUcp.php     Google UCP: /.well-known/ucp, /wp-json/ucp/v1, order events
AgenticCommerce/
  Settings, Fields, Money, Log, Signature      configuration + primitives
  Catalog, Session, SessionStore, Pricing,
  Shipping, Discounts, OrderFactory            protocol neutral shop logic (draft order = session)
  Rest/Controller + AcpController + UcpController
  Payment/{Handlers, DeferredPayment, StripeApi, StripeSharedToken, StripeGooglePay}
  Notification/{Queue, AcpOrderWebhook, UcpOrderEvents}
  Feed/AcpProductFeed
  Admin/{KeyActions, OrderMeta}
```

A checkout session is a WooCommerce order in status `checkout-draft` (hidden from the order list,
auto-deleted after 24 h of inactivity). On completion it becomes a normal order: `processing`
when the PSP captured the amount, otherwise the configured "status without capture" (default
`on-hold`). All regular WooCommerce mails, ERP exports and hooks fire as for web orders. Agent
orders are marked in the order list (column "KI") and carry a meta box with protocol, platform,
session id and transaction id.

## 2. Prerequisites

* Theme `lbwp-standard-03` (or a theme extending its `Core`) with `LBWP_S03_ENABLE_ABOON_COMPONENTS`
  defined in the site config. The shared component loads automatically with the Aboon components.
* WooCommerce 11+ with HPOS (default on all Aboon sites).
* A public HTTPS host. Both platforms call the REST endpoints and fetch the UCP profile anonymously.
* Shipping zones configured for every country that should be sellable, or the fallback shipping
  option in the settings.
* For card payments: the official `woocommerce-gateway-stripe` plugin configured with the shop's
  Stripe account (the secret key is reused), or a dedicated Stripe secret key in the settings.
  See [section 6](#6-payrexx-vs-stripe-constraints) before promising card payments.
* Legal pages (terms, privacy, returns, shipping, contact) as WordPress pages.

## 3. Per-customer setup checklist

All settings are in **Aboon → Agentic Commerce** (`admin.php?page=aboon-agentic-commerce`).
Values are stored as ACF options and can be overridden in code per theme with the
`aboon_agentic_setting` filter (`apply_filters('aboon_agentic_setting', $value, $name)`).

### 3.1 General

1. Tick **OpenAI ACP** and/or **Google UCP**. Save. The protocol groups appear after the save, and the
   theme registers the protocol components on the next request (gated via `ACF::isOptionActive()`).
2. **Testmodus**: keep it on until go-live. In test mode no PSP is called, orders land in the
   "status without capture" with an order note prefixed `TESTMODUS`, and the only declared payment
   handler is `deferred`.
3. **Artikel-ID**: `id` (product/variation id, default) or `sku`. Feeds emit exactly what the
   endpoints accept. Use `sku` only when every sellable product and variation has a unique SKU.
4. **Freigegebene Kategorien**: empty = everything. Products outside are excluded from feeds and
   rejected in sessions.
5. **Erlaubte Lieferländer**: empty = all WooCommerce shipping countries.
6. **Maximaler Bestellwert**: hard cap, `0` = off. Exceeding it blocks the session
   (`maximum_exceeded`).
7. **Status ohne Zahlungseinzug**: order status for deferred/invoice/test orders (`on-hold` default).
8. **Lieferzeit min./max.** and **Versanddienstleister**: feed the delivery windows and carrier
   name of the fulfillment options.
9. **Fallback-Versandoption**: offered when no shipping zone matches the address. Leave the title
   empty to reject such addresses (`region_restricted`).
10. **Rechtliche Seiten**: mapped to the protocol `links[]` (terms_of_use / terms_of_service,
    privacy_policy, return_policy, shipping_policy, contact).
11. **Verkäufername**: shown in feeds, the UCP profile and Stripe descriptions. Empty = blog name.
12. **Alle Anfragen protokollieren**: request tracing in *Werkzeuge → System-Log* (component
    `AgenticCommerce`). Auth failures, payment errors and delivery failures are always logged.

### 3.2 Products

Every product has an **Agentic Commerce** box in the sidebar:

* **Nicht für KI-Checkout freigeben**: removes the product (or variation) from feeds and rejects it
  in sessions.
* **Hinweis für den Agenten**: optional disclosure shown with the line item.

Products without a regular price or without an image are skipped in the feed. Packaging units
(`PackagingUnit`) and pre-order flags (`Preorder`) are honoured in sessions.

### 3.3 OpenAI ACP

1. Click **API-Key neu generieren**. The key is the bearer token OpenAI must send in the
   `Authorization` header. Hand it over together with the base URL
   `https://<host>/wp-json/acp/v1` and the API version `2026-04-17`.
2. **Signatur-Secret (eingehend)**: optional. If OpenAI provides an HMAC secret for the
   `Signature`/`Timestamp` request headers, paste it here and inbound requests are verified.
3. **Webhook-URL (OpenAI)** and **Webhook-Secret (ausgehend)**: OpenAI provides both during
   onboarding. Order lifecycle events (`order_create`, `order_update`) are POSTed there, signed
   with `Merchant-Signature: t=<unix>,v1=<hmac-sha256-hex over "<t>.<body>">`.
4. **Zahlungs-Handler**: `stripe` for card payments through shared payment tokens, `deferred` for
   invoice / payment terms (see section 6).
5. **Stripe Secret Key / Account-ID / Capture-Methode**: only for the `stripe` handler. The secret
   key falls back to the WooCommerce Stripe plugin settings (test key while its test mode is on).
   The account id (`acct_…`) is published as `merchant_id` in the handler declaration.
6. **Produkt-Feed**: tick to generate the TSV nightly (1:00, `cron_daily_1`). The URL is shown in the
   group header: `https://<host>/assets/lbwp-cdn/<ASSET_KEY>/files/shop/acp-feed.tsv`. Generate it
   once manually via the cron job endpoint
   `/wp-content/plugins/lbwp/views/cron/job.php?identifier=agentic_acp_feed&key=<MASTER_CRON_API_SECRET>`
   and register the URL with OpenAI. **Zielländer im Feed**: comma separated ISO codes.

What to send OpenAI: base URL, API key, feed URL, seller name, the list of supported countries and
currency. What to receive: webhook URL, webhook secret, optional request signature secret.

### 3.4 Google UCP

1. Click **EC-Schlüsselpaar neu generieren**. The public key is published in the profile
   (`signing_keys[]`), the private key signs order events. Regenerating invalidates the old key.
2. Verify the profile: `curl https://<host>/.well-known/ucp | python3 -m json.tool`. It must be
   reachable without authentication and list `endpoint: https://<host>/wp-json/ucp/v1`.
3. **Erlaubte Aufrufer**: one entry per line. Google service account e-mails (verified through
   `https://oauth2.googleapis.com/tokeninfo`), `aud` values, or `token:<secret>` for a static bearer
   token (used by integration tests and non-Google platforms).
4. **RFC 9421 Signatur erzwingen**: enable in production once Google's signatures verify (failures
   are logged in both modes). The platform's keys are fetched from the profile named in the
   `UCP-Agent` header and cached for an hour.
5. **Google Partner-ID** and **Google API-Key (Order Events)**: provided by Google. Order snapshots
   are POSTed to `https://shoppingdataintegration.googleapis.com/v1/webhooks/partners/<id>/events/order`
   with `X-Goog-Api-Key`, `Webhook-Id`, `Webhook-Timestamp`, `UCP-Agent` and an RFC 9421 signature.
6. **Google Pay Merchant-ID**, **Gateway Merchant-ID** (`acct_…` of Stripe), **Stripe Secret Key**,
   **Kartennetzwerke**: build the `com.google.pay` handler with
   `tokenization_specification.gateway = stripe`.
7. Product data: Google reads the existing Merchant Center feed; no additional feed is generated.

What to send Google: profile URL, endpoint, Merchant Center account. What to receive: partner id,
events API key, the service account identity that will call the endpoints.

### 3.5 Test mode smoke test

With test mode on, the sessions can be walked with curl. ACP (replace `KEY` and a product id):

```bash
BASE=https://<host>/wp-json/acp/v1
H=(-H "Authorization: Bearer $KEY" -H "API-Version: 2026-04-17" -H "Content-Type: application/json")
curl -s -X POST "$BASE/checkout_sessions" "${H[@]}" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"currency":"chf","line_items":[{"id":"1234","quantity":2}],"fulfillment_details":{"name":"Hans Muster","address":{"line_one":"Bahnhofstrasse 1","city":"Zürich","country":"CH","postal_code":"8001"}}}'
# pick fulfillment_options[].id from the response, then:
curl -s -X POST "$BASE/checkout_sessions/<id>" "${H[@]}" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"selected_fulfillment_options":[{"type":"shipping","option_id":"<option id>"}]}'
curl -s -X POST "$BASE/checkout_sessions/<id>/complete" "${H[@]}" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"buyer":{"first_name":"Hans","last_name":"Muster","email":"hans@example.ch"},"payment_data":{"handler_id":"deferred"}}'
```

UCP (static caller token from "Erlaubte Aufrufer"):

```bash
BASE=https://<host>/wp-json/ucp/v1
H=(-H "Authorization: Bearer <token>" -H "Content-Type: application/json" -H 'UCP-Agent: profile="https://platform.example/.well-known/ucp"')
curl -s -X POST "$BASE/checkout-sessions" "${H[@]}" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"line_items":[{"item":{"id":"1234"},"quantity":1}],"buyer":{"email":"max@example.ch","first_name":"Max","last_name":"Muster"},"fulfillment":{"methods":[{"type":"shipping","destinations":[{"id":"addr_1","street_address":"Weg 3","address_locality":"Bern","postal_code":"3000","address_country":"CH"}],"selected_destination_id":"addr_1"}]}}'
# PUT is a full replacement: resend line_items, buyer and fulfillment with groups[].selected_option_id
curl -s -X POST "$BASE/checkout-sessions/<id>/complete" "${H[@]}" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"payment":{"instruments":[{"id":"i1","handler_id":"deferred","type":"card","selected":true,"credential":{"type":"PAYMENT_GATEWAY","token":"{}"}}]}}'
```

Expected: an order in "In Wartestellung" with a `TESTMODUS` note, the KI column in the order list,
and (when webhook settings are filled) delivery entries in the system log.

### 3.6 Go-live

1. Turn **Testmodus** off. With the `stripe` handler the declared handler becomes
   `card_tokenized` (ACP) / `com.google.pay` (UCP) automatically when a Stripe key resolves.
2. Enable **RFC 9421 Signatur erzwingen** for UCP.
3. Verify the webhook secret / events API key with one real order.
4. Check that the nightly feed ran (`agentic_acp_feed_last_run` option, system log entry).

## 4. Operations

| Item | Where |
|---|---|
| ACP endpoints | `POST /wp-json/acp/v1/checkout_sessions`, `GET/POST …/{id}`, `POST …/{id}/complete`, `POST …/{id}/cancel` |
| UCP endpoints | `POST /wp-json/ucp/v1/checkout-sessions`, `GET/PUT …/{id}`, `POST …/{id}/complete`, `POST …/{id}/cancel`, profile `GET /.well-known/ucp` |
| Logs | Werkzeuge → System-Log, component `AgenticCommerce` (tokens and cards are masked) |
| Outbound queue | table `lbwp_data`, row key `agenticnotify`; 3 immediate attempts, then hourly retries (`cron_hourly`), dead letter after 24 attempts |
| Manual retry | cron job `agentic_notification_retry` |
| Feed | nightly `cron_daily_1`, manual `agentic_acp_feed` cron job, last run in option `agentic_acp_feed_last_run` |
| Draft cleanup | WooCommerce deletes `checkout-draft` orders after 24 h; the shared component additionally runs on `cron_daily_4` (drafts older than 2 days) |
| Rate limit | 60 requests per minute per session id / credential (`aboon_agentic_rate_limit` filter) → HTTP 429 |
| Idempotency | stored per endpoint + `Idempotency-Key` on the draft order; replays return the identical body with `Idempotent-Replayed: true`; same key + different body → 422 (ACP) / 409 (UCP); concurrent requests → 409 `idempotency_key_in_flight` |
| Order meta | `_agentic_protocol`, `_agentic_session_id`, `_agentic_platform`, `_agentic_payment_txn`, `_agentic_tracking`, `_agentic_order_events` |

Shipping notifications: ERP or fulfillment code should call
`do_action('aboon_agentic_order_shipped', $orderId, ['number' => '…', 'carrier' => '…', 'url' => '…'])`.
This sends `shipped` (ACP `order_update`, UCP fulfillment event). Order status `completed` sends
`fulfilled` (ACP) / `delivered` (UCP); `cancelled` and refunds send the corresponding updates.

Useful hooks:

| Hook | Purpose |
|---|---|
| `aboon_agentic_setting` (filter) | override any option per theme |
| `aboon_agentic_product_eligible` (filter) | custom eligibility rules |
| `aboon_agentic_fulfillment_options` (filter) | replace/adjust shipping options (cart-bound custom shipping code) |
| `aboon_agentic_shipping_package` (filter) | adjust the package before zone calculation |
| `aboon_agentic_payment_handlers` (filter) | add or replace payment handlers |
| `aboon_agentic_stripe_intent_params` (filter) | extend PaymentIntent parameters |
| `aboon_agentic_acp_feed_item` (filter) | adjust feed rows |
| `aboon_agentic_acp_order_status` / `aboon_agentic_acp_order_payload` (filters) | ACP webhook status and payload |
| `aboon_agentic_ucp_order_entity` / `aboon_agentic_ucp_profile` (filters) | UCP order snapshot and profile |
| `aboon_agentic_order_finalized` (action) | order created from a session (ERP integration point) |
| `aboon_agentic_marketing_consent` (action) | newsletter consents from ACP complete |

## 5. Alternatives that ship with WooCommerce and Stripe (and why they are not used)

* **WooCommerce core `wc/agentic/v1`** (Store API, feature flag `agentic_checkout`): implements ACP
  checkout sessions but authenticates with Jetpack blog tokens and needs a gateway that declares
  the `agentic_commerce` feature (WooPayments). LBWP sites run neither Jetpack nor WooPayments.
* **Stripe gateway "Agentic Commerce"** (`woocommerce-gateway-stripe` 10.8.5, flag
  `_wcstripe_feature_agentic_commerce`): Stripe hosts the ACP endpoints and the product catalog
  (uploaded through the Stripe Files API); the shop only answers `v1.delegated_checkout.*` webhooks
  for shipping/tax. It covers ACP only, requires a Stripe account with the preview feature, and
  cannot serve Payrexx shops or Google UCP.

The LBWP implementation supports both protocols, needs no Jetpack, keeps the feed on the LBWP CDN,
and runs the deferred/invoice mode for Payrexx shops.

## 6. Payrexx vs Stripe constraints

Both protocols deliver a **delegated payment token** to the shop; the shop must charge it server
side. Only PSPs that accept such tokens can capture card payments.

| | Stripe | Payrexx |
|---|---|---|
| ACP (ChatGPT) card payment | **Yes.** Shared payment token `spt_…` → `POST /v1/payment_intents` with `payment_method_data[shared_payment_granted_token]`, `confirm=true`, capture method from settings. Handler `card_tokenized` (`dev.acp.tokenized.card`). | **No.** The Payrexx SDK (`resources/libraries/payrexx`, `woo-payrexx-gateway`) only supports hosted Gateway pages/redirects and charging a stored PSP reference (`setPsp` + `setChargeOnAuthorization`). It cannot consume a shared payment token. |
| ACP without card capture | Possible as a second handler. | **Only option.** Handler `deferred` (`dev.acp.deferred`): the order is created without capture in the configured status (default `on-hold`), the shop invoices the buyer (`payment_terms` net_15/30/60/90 are echoed). Whether OpenAI accepts a merchant without a card handler must be confirmed during onboarding; treat it as a B2B / invoice pilot. |
| UCP (Google) card payment | **Yes.** Google Pay `PAYMENT_GATEWAY` token with `gateway: stripe` → Stripe token id → PaymentIntent (`payment_method_data[card][token]`). Handler `com.google.pay`. Needs the Stripe account id as gateway merchant id and a Google Pay merchant id. | **No.** Payrexx cannot process a Google Pay gateway token. Google's surface requires a Google Pay handler, so **do not enable UCP on Payrexx-only shops**. |
| 3-D Secure / interventions | Declined (`requires_3ds` message); sessions requiring authentication cannot complete. Enable `supports_3ds` later when interventions are implemented. | n/a |
| Refunds | Handled in WooCommerce/Stripe as usual; refunds are reported to the platforms as adjustments. | Manual (invoice credit note); reported as adjustments. |
| Test mode | Uses the `sk_test_` key from the Stripe plugin (test mode) or the dedicated field. Test mode of the component never calls a PSP at all. | Test mode = deferred handler. |

Decision matrix per customer:

| Customer setup | ACP | UCP |
|---|---|---|
| Stripe gateway live | card payments (`stripe` handler) | Google Pay via Stripe |
| Payrexx only, B2C | not recommended (no card capture) | not possible |
| Payrexx only, B2B/invoice | `deferred` handler, orders on hold, manual invoicing | not possible |
| Payrexx + Stripe (Stripe added only for agents) | `stripe` handler; regular web checkout stays on Payrexx | Google Pay via Stripe |

Note on the Stripe API: the shared-payment-token parameter follows the current Stripe docs
(`payment_method_data[shared_payment_granted_token]`). Should Stripe require a specific API
version for it, set it with the `aboon_agentic_stripe_version` filter.

## 7. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| 401 on every request | Wrong bearer (ACP: API key; UCP: caller not in "Erlaubte Aufrufer" or tokeninfo rejected). Check the system log entry `auth failure`. Servers stripping `Authorization` are handled (`REDIRECT_HTTP_AUTHORIZATION`). |
| 401 `invalid_signature` (UCP) | Platform profile unreachable or key id unknown; disable "Signatur erzwingen" temporarily, compare the logged reason (`unknown_keyid`, `content_digest_mismatch`, `signature_expired`). |
| 404 unknown session | Session id malformed or the draft was cleaned up (24 h). |
| 409 `idempotency_key_in_flight` | Parallel request on the same session or create key; the platform retries. |
| 422 / 409 `idempotency_key_conflict` | Same `Idempotency-Key` with a different body. |
| `region_restricted` | Country not in allowed countries, or no shipping zone matches and no fallback option is configured. Custom cart-bound shipping code should hook `aboon_agentic_fulfillment_options`. |
| Empty `fulfillment_options` after a valid address | Shipping zone for the country missing; check WooCommerce → Versand. |
| `maximum_exceeded` | Order total above the configured cap. |
| Session never `ready_for_payment` / `ready_for_complete` | Blocking message present; UCP additionally requires `buyer.email`. Read `messages[]`. |
| Payment declined with `requires_3ds` | Card requires authentication; not supported. |
| Webhook / order events missing | Check `lbwp_data` rows with key `agenticnotify` and the system log; the hourly cron retries. Locally (`LOCAL_DEVELOPMENT`) the cron master is not available, run `agentic_notification_retry` manually. |
| Feed empty | Products need a regular price and an image; excluded products/categories are skipped. |
| Stale responses in the cluster | All REST responses set `HTMLCache::avoidCache()` and no-cache headers; the profile is cached one hour on purpose. |
