# CareSys Register and Agency Test Report 27 October 2026

**Status: PASS**
Report date uses America/Los_Angeles. Browser execution completed at 2026-10-28T06:19:25.047Z UTC.

## Result

- **254 backend tests and 1411 assertions passed**, with no failures, errors or skipped tests.
- **176 registration and notification cases** include 33 registration variants, all 128 combinations of optional fields, and 15 workflow, permissions and consistency cases.
- **28 browser checks passed** across desktop and 390-pixel mobile layouts.
- Nine screenshots document registration, administrator review and owner decisions.
- The disposable test account, agency and its notifications were removed. Existing accounts were preserved. No Stripe card setup or payment was performed.

## Delivered workflow

1. An authenticated user registers an agency. The agency is pending and billing remains awaiting card.
2. Every active platform administrator receives an in-app review notification. The agency owner receives a pending-review receipt.
3. The administrator opens the notification, reviews the agency's legal name, official email, phone numbers, optional registration number and address, and records a decision and review basis.
4. Verify and approve sets verification to verified. Reject and suspend produce their own distinct statuses and messages.
5. All active owner memberships receive the decision and review note. Notification history remains available in the personal account even when agency access is locked or suspended.
6. The top-right bell displays an unread count and menu. Items can be marked read. Counts refresh every 30 seconds while the page is visible and when the menu opens.
7. Registration retries with the same form key return the original agency without duplicate alerts. A changed payload with that key is rejected.

Verification does not save a card, start a free trial, select modules or record a subscription payment. These remain separate billing steps.

## Backend coverage

| Area | Cases covered | Result |
| --- | --- | --- |
| Required registration details | Empty/overlong legal name, missing/malformed official email, malformed/unknown country, currency format/length and invalid timezone | PASS |
| Optional phones | None, business only, shared mobile/contact, separate mobile/contact, all different numbers, disabled stale input ignored, missing selected values and landline rejected as mobile | PASS |
| Optional address | US state/ZIP, Canadian province/postal code, UK optional county, UAE without postal code; missing street/city, invalid state and invalid postal format | PASS |
| Optional combinations | All 32 phone checkbox combinations crossed with address on/off and business registration supplied/omitted: 128 combinations | PASS |
| Registration persistence | Owner membership, pending verification, awaiting-card billing, normalized contact snapshots and no partial save after invalid input | PASS |
| Duplicate submissions | Same account/key retries return one agency; changed details rejected; another account cannot reuse the first account's registration | PASS |
| Notification delivery | Administrator review request, owner pending receipt, approved/rejected/suspended/reapproved decisions, multiple administrators/owners, inactive administrator excluded | PASS |
| Permission boundaries | Guest access denied, non-operator approval denied, revoked platform role denied, disabled account registration denied, recipient-only reads and current-role filtering | PASS |
| Consistency and audit | Stale version denied, repeated current decision does not spam alerts, notification-storage failure rolls back registration/approval, UTC creation and read times | PASS |
| Output safety | Agency markup escaped in notification HTML; unknown or another recipient's reference returns unavailable | PASS |
| Regression | Existing recruitment, consent, employment, scheduling, multi-agency, billing, invoices, API authentication and database-isolation suites | PASS |

## Browser checks

| No | Check | Result | Detail |
| --- | --- | --- | --- |
| 1 | Guest cannot read notification menu | PASS | HTTP 302 |
| 2 | Personal account registration succeeds | PASS | {"url":"https://localhost:8444/app","alerts":[]} |
| 3 | New account has empty inbox | PASS |  |
| 4 | Phone fields hidden until selected | PASS |  |
| 5 | Address hidden until selected | PASS |  |
| 6 | Agency registration redirects to billing | PASS |  |
| 7 | Registration does not enable Stripe or charge | PASS |  |
| 8 | Notification menu available on locked billing | PASS |  |
| 9 | Owner receives pending review receipt | PASS |  |
| 10 | Agency owner cannot access platform review | PASS |  |
| 11 | Registration retry redirects to same agency | PASS |  |
| 12 | Administrator receives registration review alert | PASS |  |
| 13 | Owner cannot mark administrator notification as read | PASS | HTTP 404 |
| 14 | Notification read requires CSRF token | PASS | HTTP 403 |
| 15 | Review shows official contact and address | PASS |  |
| 16 | Platform decision verified | PASS |  |
| 17 | Owner receives verified and approved notification | PASS |  |
| 18 | Owner can mark decision read | PASS |  |
| 19 | Platform decision rejected | PASS |  |
| 20 | Notification count updates without page reload | PASS |  |
| 21 | Rejected status notification is accurate | PASS |  |
| 22 | Platform decision suspended | PASS |  |
| 23 | Suspended owner can still view personal notifications | PASS |  |
| 24 | Suspension restricts agency access | PASS |  |
| 25 | Platform decision verified | PASS |  |
| 26 | Approval leaves trial awaiting card | PASS |  |
| 27 | Mobile notification panel stays below trigger and inside viewport | PASS |  |
| 28 | Notification menu available on platform policy page | PASS |  |

## Findings and resolutions

- Added the notification table, private inbox/read actions and shared top-right menu on personal/agency workspaces, locked billing and platform billing-policy pages.
- Added transaction-bound registration and verification notifications so a storage failure cannot leave a successful registration or approval without its alerts.
- Added per-form registration idempotency and live authorization rechecks for disabled accounts or revoked platform roles.
- Notification creation times are explicitly stored in UTC to match the UI labels. Mobile popup placement is constrained below its trigger and inside the viewport.
- The browser harness follows the application's account-name rules and accepts its correct HTTP 303 post/redirect response. Test teardown uses the desktop sign-out control after the mobile viewport check.

## Evidence files

- [Backend console result](backend-tests.txt)
- [JUnit results](backend-junit.xml)
- [Named scenarios](scenario-results.txt)
- [Full test case matrix](test-case-matrix.csv)
- [Browser results](browser-results.json)
- [Disposable-fixture cleanup result](cleanup-results.json)
- [Screenshot evidence](Screenshots.md)

## Reproduction

Run the backend suite from the CareSys repository:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --no-coverage
```

For browser checks, start the existing local HTTPS web/API servers and provide the Playwright package directory as CARESYS_NODE_MODULES, then run `node tests/Browser/register-agency-notifications.cjs`. The harness uses the existing local platform demonstration account, creates a disposable account with a random password, and stores its credentials only in the ignored `build/qa/registration-notification-session.local.json` file. After the browser run, execute `C:\xampp\php\php.exe tests/Browser/cleanup-register-agency.php`. The cleanup verifies the fixture identity, agency, absence of operational records and unchanged other account IDs before removing only its own fixture. It also removes the private fixture manifest after successful cleanup. Never seed or reset existing user accounts to run this scenario.

## Scope and limits

These are local tests of the implemented in-app workflow, not email/SMS/push delivery or production certification. Country examples cover US, Canada, UK and UAE; country formatting and phone metadata are exercised through the installed libraries. Postal validation checks format rather than deliverability. The optional-field checkbox combinations are exhaustive for the seven tested binary choices; arbitrary text inputs and every possible real-world business arrangement cannot be exhaustively enumerated. Inboxes show the most recent 100 eligible notifications and the menu shows the latest 10. With no active platform administrator, registration stays pending and the owner still receives a receipt; no recipient is invented. No existing pending registrations were retroactively notified. No real contact details, passwords, API keys or card information are included in this report.

## Word report

[Download the dated Word test status report](CareSys_Register_and_Agency_Test_Status_27_October_2026.docx). Includes test results, coverage, cleanup details and all nine screenshots.

