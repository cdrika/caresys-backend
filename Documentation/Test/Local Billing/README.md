# Local billing simulator test status 27 October 2026

Status: PASS. This development-only option supports interactive billing tests while Stripe is unconfigured. No card number is entered, no provider request is made and no money moves.

## How to test

1. Refresh the agency billing page on https://localhost:8444.
2. In Local billing test mode, confirm the test action and select Simulate card setup.
3. Choose modules, package and worker count, then request the subscription quote. Download the generated PDF. The configurable trial period starts at module selection.
4. Continue to the agency workspace for ordinary module testing. Normal platform verification and agency permissions still apply.
5. To test expiry without waiting, return to billing and confirm End test trial now. Agency members are locked; the owner sees billing approval controls.
6. Confirm Approve simulated invoice payment. This marks the test invoice paid and grants 30 days of simulated access. Repeating the same approval does not extend access.

## Configuration and boundaries

The ignored local .env has billing.localSimulator = true. Set it to false to stop simulation. The default committed configuration is false. Development or testing environment and a direct loopback request are required; CLI tests are also allowed. Production ignores this flag. No new unauthenticated endpoint is introduced: forms use existing authentication, owner checks, CSRF protection, workspace nonce and billing throttling.

Simulated billing uses card brand TEST and last four 0000, not a real card token. Every generated test invoice snapshots simulated_billing and displays TEST ONLY on every PDF page. All simulator actions are audited as simulated. Existing Stripe customer/card accounts cannot be altered by the simulator. Simulated accounts cannot enter Stripe checkout. Disabling the simulator locks their access and prevents further invoice selection. Use a fresh agency for real Stripe billing; simulated invoices must not be treated as actual receipts or moved into production.

## Verified results

- Full backend suite: 256 tests, 1429 assertions passed.
- Dedicated billing integration file: 4 tests, 41 assertions passed. Covers trial boundaries, owner approval, repeated payment, simulated access disablement, member locking, consent, invoice marking and protection of Stripe accounts.
- Browser: all eight checks passed; see browser-results.json. Disposable registration, enabled controls, card-free setup, invoice-triggered trial, PDF download, test label, expiry and simulated payment approval.
- Four screenshots in screenshots show initial mode, active trial and invoice, expiry and simulated payment approval.
- Guarded fixture cleanup succeeded, preserving all other accounts and Stripe settings; see cleanup-results.json. Home Agency was not modified.

## Repeat

Run C:/xampp/php/php.exe vendor/phpunit/phpunit/phpunit --no-coverage. For the browser set CARESYS_NODE_MODULES to the bundled node_modules directory and run node tests/Browser/local-billing.cjs, then C:/xampp/php/php.exe tests/Browser/cleanup-local-billing.php. Never run a second browser fixture before cleaning the prior fixture: its private manifest is in ignored build/qa/local-billing-session.local.json.
