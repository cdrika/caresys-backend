# Agency free trial and owner-approved payments

Implemented 27 October 2026. Scope: application, browser and mobile web services, and CareSys mobile client. The public marketing website is unchanged.

## Lifecycle

1. Registering an agency creates an `awaiting_card` subscription. Agency workspace data is unavailable until onboarding completes. Personal accounts remain independent of agency subscriptions.
2. An owner consents to Stripe-hosted **setup-mode** Checkout. This saves/verifies the card without collecting a subscription payment. CareSys receives only Stripe references, card brand and last four digits; card numbers and CVC never enter CareSys forms or storage. A bank can show a temporary card verification authorization.
3. CareSys verifies completed card setup server-to-server. The owner then selects modules, package preference and worker coverage. Server pricing includes dependencies and selects the lowest eligible package/add-on total. An immutable pro forma invoice with descriptions and free-trial terms is issued and downloadable.
4. The first successful module selection starts the free period, **30 elapsed days by default**, after verified card setup. It is not a calendar-month calculation. Selected package modules are available during the trial, subject to existing role, verification and consent rules. Changing modules, requesting another invoice or replacing a card never restarts a trial.
5. At the exact UTC end timestamp (`now >= trial_ends_at`), all agency reads/writes are blocked with HTTP **402**, including existing browser sessions and bearer tokens. Owners are directed to billing and prompted to approve payment. Other members receive only an agency-locked notice and owner-contact instruction, without card or invoice information. Agency owners can still manage billing and download invoices. Personal records and other agencies are not locked.
6. No charge happens automatically. Only an owner's explicit approval after expiry can create **payment-mode** Stripe Checkout. Stripe shows the final amount and any configured tax before payment. Success is verified from Stripe, not inferred from a return URL or client assertion. Failed, canceled, incomplete, forged or mismatched payments do not unlock access.
7. A verified payment activates the invoice's module scope for **30 elapsed days**, starting at verification. At paid-period expiry, the agency locks again and the owner must request a fresh invoice and approve another payment. No Stripe recurring subscription, off-session charging job or automatic card debit is created.

Existing commercially active agency agreements are preserved as `legacy_active` until deliberately moved into the managed billing flow. Other existing agencies are enrolled as awaiting card. Newly registered agencies always use the managed lifecycle.

## Administrative policy

The singleton `cs_billing_policy` row (ID 1) stores `trial_days` (default 30), version and update timestamp. Platform administrators use **Platform operations → Free trial policy**, or `/app/billing-policy`, to choose 1–365 days. Updates use optimistic version checks and are audited.

The number of days and actual start/end timestamps are copied to each agency when its trial begins. Policy changes apply to future trials; they do not silently shorten or extend an existing trial. `cs_agency_subscriptions` stores trial timestamps, paid-until timestamp, provider customer reference, masked card details and current invoice reference. `cs_billing_checkouts` stores approved provider operations and verification status, including the final paid amount. Financial invoice snapshots remain in `cs_subscription_invoices`.

## Stripe configuration (required before real card entry)

Configure secrets in the server environment or ignored `.env` file; never commit keys or send card details to CareSys:

```ini
billing.stripeSecretKey = 'your Stripe test or live secret key'
billing.stripeWebhookSecret = 'the signing secret for this webhook endpoint'
billing.webBaseURL = 'https://your-web-host'
billing.automaticTax = false
```

Use test keys and Stripe test cards first. Verify seller billing details, tax treatment and Stripe account activation before accepting live payments. Enable automatic tax only after configuring Stripe Tax registrations/product treatment; otherwise the configured checkout charge is the pre-tax catalog subtotal. Stripe remains the source of the final payment receipt; the CareSys PDF is not a tax invoice.

Register the **web host** endpoint `POST /billing/stripe/webhook` for `checkout.session.completed` and `checkout.session.async_payment_succeeded`. Its CSRF exemption is restricted to that exact path; signed payloads must pass HMAC verification and a five-minute timestamp tolerance. The handler then retrieves the authoritative Checkout Session from Stripe. Delivery retries and duplicate returns do not grant another paid period. The owner can also press **Verify completed checkout** after returning from Stripe, so local development can complete without a publicly reachable webhook.

The separate API host needs its own restricted database credentials and the same billing configuration. `deploy/local/start.ps1` copies only the default database and billing settings into the isolated API environment; restart the local web/API processes after changing keys. Container runtime examples include billing settings and the PHP cURL extension. Docker configuration has not been run on this machine.

If keys are missing, the UI explains that setup is unavailable and the server returns 503 for provider operations. It never simulates a verified card or successful payment. Fake Stripe responses exist only in automated tests, injected into the billing service.

## API contract

Browser prefix `/api/v1`; native prefix `/mobile/v1`. Browser mutations require session, CSRF and `X-Workspace-Nonce`; native requests require a scoped mobile bearer token, JSON for writes and no cookies. Tenant references are explicit and active membership is checked on every request.

| Method and suffix | Behavior |
|---|---|
| `GET /me/agencies` | Memberships and minimal agency access flags |
| `GET /agencies/{agency}/billing` | Owner: trial/payment status, masked card, current selection and invoice list. Member: state/locked/owner/notice only |
| `GET /agencies/{agency}/billing/catalog` | Owner-only pricing, module labels/descriptions, dependencies and packages |
| `POST /agencies/{agency}/billing/invoices` | Owner-only `{modules, service_level, worker_count, request_key}`; card required for managed trials |
| `GET /agencies/{agency}/billing/invoices/{ref}/download` | Owner-only authenticated PDF download |
| `POST /agencies/{agency}/billing/checkout/setup` | Owner-only `{request_key, confirm:"yes"}`; returns `{ref,purpose,url,state}` |
| `POST /agencies/{agency}/billing/checkout/payment` | Owner-only `{request_key, confirm:"yes", invoice_ref}`; only after trial/paid expiry and for the current unexpired issued invoice |
| `POST /agencies/{agency}/billing/checkouts/{ref}/complete` | Owner-only `{}`; retrieves and verifies provider state |

Request keys are 32 lowercase hex characters. Reuse the same key when retrying the same operation; use a fresh key for a different selection or purpose. Stripe idempotency keys are scoped to agency plus request key. Only one pending payment checkout is allowed per agency, preventing a second checkout from charging the same invoice. Once a session expires, a new request can create another checkout. A paid invoice cannot be used to buy another period; request a fresh invoice.

Agency operations return 402 after expiry even when authentication remains valid. Clients should clear cached agency data, show billing only to owners, and show the generic locked notice to members. Authentication failures remain 401; role/tenant authorization remains 403/404. Invalid provider confirmations remain 409. No provider key or raw card detail is returned by these APIs.

## Mobile client and validation

The previously documentation-only mobile repository now contains an Expo/React Native client for pairing, agency selection, trial status, module selection, estimates, card setup, explicit payment approval and PDF saving/sharing. Tokens are bounded to 15 minutes and stored using Expo SecureStore; invoice files use private temporary cache and are deleted after sharing. Checkout opens Stripe in the system browser; bearer credentials are never placed in a URL. The API server enforces access even if the app is stale or tampered with.

Automated coverage includes card prerequisites, trial-policy snapshots, exact expiry boundaries, unchanged trial start after reselection, no payment checkout during trial, failed/mismatched provider responses, duplicate checkout/verification handling, owner/member privacy, live browser redirects, mobile bearer access, PDF downloads and role-protected policy administration. Native JavaScript bundles are exported for build validation. A signed device binary and real Stripe test/live checkout require device tooling, trusted reachable TLS hosts and provider credentials.

Provider references: [Stripe Checkout Sessions](https://docs.stripe.com/api/checkout/sessions/create), [retrieve and verify sessions](https://docs.stripe.com/api/checkout/sessions/retrieve), [save payment methods without payment](https://docs.stripe.com/payments/save-and-reuse).
