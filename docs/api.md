# Core JSON API

Core routes under /api/v1 use the same services as web forms. Authentication is
the Shield caresys_session cookie. Browser mutations require X-CSRF-TOKEN and
X-Workspace-Nonce from the current workspace form. Workspace switching invalidates
the previous nonce. Public references never replace membership or consent checks.

| Method | Endpoint | Behavior |
| --- | --- | --- |
| GET, PATCH | /me/profile | Own profile and exact edit version |
| POST | /me/profile/publish | Explicit preview publication or pause |
| GET | /agencies/{agency}/candidates | Approved summaries; purpose required; 25 per page |
| POST | /agencies/{agency}/history-requests | History request after candidate account confirmation |
| POST | /me/history-requests/{request}/decision | Selected entry versions; approve/decline/withdraw |
| GET | /agencies/{agency}/history-requests/{request}/entries | Named recipient and current grant only |
| POST | /me/contracts/{contract}/accept | Individual exact-version decision |
| POST | /agencies/{agency}/contracts/{contract}/accept | Authorized agency agreement |
| POST | /me/joining-requests/{joining}/decision | Separate joining authorization |
| POST | /agencies/{agency}/joining/{joining}/activate | Idempotent activation; Idempotency-Key required |

References are 32 lowercase hexadecimal characters. Writable fields are allowlisted.
Profile edits include version. Contract decisions include version, digest, decision,
confirm=yes and the current password for step-up. Never place a password in a URL.

Responses contain data and correlation_id, or error:{code,message,correlation_id}.
Statuses include 401, 403, 404, 409, 422 and 429. Browser unauthenticated requests
may follow Shield's redirect-to-login behavior. Protected data uses no-store caching.

API timestamp fields use ISO-8601 UTC strings; decimal values remain strings.
A core OpenAPI contract is in openapi.yaml. Full specification-wide schemas and
integration-token authentication remain integration work.

Operational POST routes are also implemented under /agencies/{agency}: visits,
clients, clock-events, leave-requests, assets, expenses, notes, payroll-exports and
invoices. Versioned review routes include timesheets/{ref}/approve,
leave-requests/{ref}/decision, assets/{ref}/custody, expenses/{ref}/decision,
invoices/{ref}/receipt and invoices/{ref}/credit. These use the same explicit
field allowlists and authorization services as their server-rendered forms.

## Multi agency part time scheduling

Separate agreed `part_time` employments can be active across agencies. Visit creation checks overlaps and approved leave across the person, without returning another agency's records. See `Documentation/Status_Reports/2026-10-27/CareSys_Multi_Agency_Shift_Workflow_27_October_2026.md`.

| Method | Path under /api/v1 | Input / result |
| --- | --- | --- |
| POST | /agencies/{agency}/shift-availability | `employment_ref`, `client_ref`, `starts_at`, `ends_at`; returns availability flags and up to three local time suggestions. |
| POST | /agencies/{agency}/shift-requests | Same fields plus `service` and optional `required_qualification`; returns request ref, version, state and expiry. Does not change visits. |
| GET | /me/shift-requests | Only the actor's requests, proposed visit and their own prior shifts to replace. |
| POST | /me/shift-requests/{reference}/decision | `version`, `decision` approve/decline; approval also needs `confirm=yes` and current `password`. Returns state and resulting visit ref. |

Request times and suggested times use `YYYY-MM-DDTHH:mm` in the route agency timezone. Prior-shift times in the caregiver response and request expiry are UTC. These are scheduling gaps, not confirmed work-hour or travel availability. Shift length is limited to 24 hours and suggestions search seven days. All normal role, access, CSRF and workspace checks apply. Stale, expired, leave-blocked or started-shift replacements fail with no cancellations. A request expires within 24 hours or at its proposed start.

## In-app agency registration notifications

These browser-session routes are outside the /api/v1 namespace:

- GET /notifications/menu returns only the current actor's eligible unread count and escaped menu HTML. It uses no-store caching and requires authentication.
- POST /notifications/{reference}/read marks only an eligible notification addressed to that actor. It requires the session CSRF token, has an explicit input allowlist, and redirects to the personal inbox. Notification read state is personal and independent of the currently selected agency, so no agency workspace nonce is needed for this route.
- GET /app/notifications opens the personal inbox, including while agency access is locked or suspended.

The personal registration form includes a 32-hex registration_key. Replaying a key for the same actor and normalized registration returns the original agency; changed data is rejected. Platform verification remains restricted to a live platform operator and requires the current agency version and a review basis. Approval does not change billing or module entitlements. Registration and verification write their recipient-specific notifications in the same database transaction as the business change. These are in-app messages; the existing provider outbox is not treated as delivered email or push.
