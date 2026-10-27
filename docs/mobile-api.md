# Mobile web services

Base path: `/mobile/v1`, HTTPS required in every environment. The running local API has its own HTTPS host/process at `https://127.0.0.1:8443`; browser pairing is at `https://localhost:8444/mobile-enrollment`. A pre-boot gate isolates the two surfaces. They share CareSys code and its database, preserving current tenant/role checks. See [local deployment](../deploy/local/README.md) for startup and shutdown.

## Authentication and enrollment

For local testing without a native app, use the **Open local test device** link on the approval screen, or visit `https://127.0.0.1:8443/mobile/v1/pairing/test-device`. It generates a real five-minute code, waits for browser approval, checks authenticated profile access and provides a disconnect button. This helper is restricted to development + direct loopback HTTPS; it is unavailable in production. Example codes in documentation are not valid credentials.

1. The native app sends `POST /mobile/v1/pairing/requests` with JSON `{"device_name":"My phone"}` and no cookies or credentials. The response contains `data.device_code` (private to the app), `user_code`, `verification_uri`, `expires_in: 300`, and `interval: 5`.
2. Show the user code on the device. Open `verification_uri` in the browser. The user signs in through the existing browser login, enters the code, reviews the requesting device, confirms their password and approves. Browser forms use session authentication and CSRF. Codes cannot be approved after their five-minute expiry.
3. While approval is pending, poll `POST /mobile/v1/pairing/token` with JSON `{"device_code":"<private device code>"}` at five-second intervals. Pending requests return HTTP 428 with `AUTHORIZATION_PENDING`. Once approved, HTTP 200 contains `data.access_token`, `token_type: Bearer`, and `expires_in: 900`. Redemption is transactionally locked and one-time; expired, unknown and consumed codes are rejected. Neither device nor user codes are stored in plaintext.
4. The browser pairing screen lists unexpired mobile tokens and lets the owner revoke a selected device. Revocation affects the next API call. The native client can also call `DELETE /mobile/v1/auth/token`.

Store the delivered credential in the mobile OS credential store. It is returned directly to the device, never through the approval page or a redirect URL. Do not put credentials in URLs, logs, analytics, or browser local storage. There is no password-only mobile login, refresh token, or automatic renewal. Pair again after token expiry. The browser flow honors configured authentication actions without bypassing them. Shield stores the bearer token's SHA-256 hash, and mobile authentication avoids Shield's raw-credential failure logging.

The original browser-only `POST /mobile-enrollment/tokens` endpoint remains for trusted integrations with session, CSRF and password confirmation. Normal mobile clients use the pairing flow above. Limits: pairing creation 5/minute/IP, polling 60/minute/IP, browser review/approval 10/five minutes/account. Authentication responses carry `no-store`.

Send `Authorization: Bearer <access_token>` on every mobile request. Do not send cookies. Mutations require `Content-Type: application/json` with a JSON object (maximum 64 KiB). `DELETE /mobile/v1/auth/token` revokes the current credential immediately. Tokens have the exact `mobile:v1` scope and a maximum 15-minute lifetime; other Shield tokens are rejected. Account status is checked for every request. Limits: 120 requests/minute/IP and 60/minute/account, plus existing operation limits.

## Resources

### Agency trials and billing

The mobile API now exposes agency access status, owner-only module invoices, Stripe card setup and explicit payment approval. See [the complete trial/billing contract](../Documentation/Agency_Free_Trial_and_Payments.md#api-contract). On HTTP 402, clear agency content and show billing for owners or a generic locked notice for other members. A saved card never authorizes automatic charging; checkout for payment is blocked until the trial or paid period expires.

Mobile routes mirror the existing `/api/v1` resource suffixes and JSON fields:

- `GET/PATCH /me/profile`, `POST /me/profile/publish`.
- `GET /agencies/{agency}/candidates` and history request/entry endpoints.
- Contract acceptance, joining decisions and activation.
- Agency visits, clients, clock events, leave, assets, expenses, notes, payroll exports and invoices, including their existing decision endpoints.

See `app/Config/Routes.php` and `app/Controllers/ApiController.php` for the explicit route and writable-field allowlists. Existing validation, version checks, consent requirements, idempotency keys, password step-up and role/module checks apply. Every agency route creates a Workspace using the token actor and route agency, rechecking active membership. Browser workspace nonces are replaced by that explicit route context for mobile calls. Browser APIs retain their session, CSRF and workspace nonce requirements.

Responses use the existing `data`/`error` envelopes. Authentication errors are JSON, with no login redirect. Mobile responses are marked `no-store`; missing credentials return 401, invalid scopes return 403 and throttling returns 429 with Retry-After.

## Deployment isolation

The local setup runs two independent loopback-bound Apache processes with a generated localhost TLS certificate. It forwards bearer headers, excludes query strings and headers from access logs, disables API debug output, uses a separate API environment/writable directory and denies browser/static routes on the API host. Browser hosts deny mobile routes including front-controller aliases. The API environment receives only the existing restricted DML database account; migration/test credentials stay outside it. Local processes share the Windows user account, so this is a host/process boundary, not an OS sandbox.

An optional [container deployment](../deploy/container/README.md) provides separate web/API containers and networks behind a TLS proxy. Docker is absent on this machine, so that configuration is prepared but has not been built or run. Configure separately provisioned restricted credentials before using it. A shared throttler/session store is required before scaling; the supplied local deployment is for one API instance.
