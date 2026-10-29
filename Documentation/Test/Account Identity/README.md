# Account identity rules and test status 29 October 2026

## Rules

A personal login email and username belong to one account across every agency. Changing agency membership, cancelling a subscription, disabling, suspending, closing or soft-deleting an account never releases these identifiers. Returning users sign in to the same account or contact support for restoration; registration never reactivates a blocked account.

Registration trims usernames and trims and lowercases email addresses. Existing case-insensitive database unique constraints reserve users.username and the auth_identities type and secret combination. Registration validation includes all account states. Error messages direct users to their existing account. User and email-password identity inserts are wrapped in one transaction so a concurrent duplicate cannot leave a partial new account.

Phone numbers, mobile numbers and physical addresses can be shared by households, colleagues and branches. Agency correspondence email can also be shared, and is normalized to lowercase. These values are not credentials, ownership proof or a reason to merge accounts. Agency records remain isolated by agency ID and user membership. Existing record IDs, ownership and history are unchanged. Names and email aliases are not merged automatically.

## Verification

Full backend regression suite: 266 tests, 1481 assertions passed.

Nine dedicated integration cases passed with 34 assertions. Coverage includes active, inactive, suspended, banned, closed and soft-deleted accounts; case variants; direct duplicate insert rejection by the database; and two agency registrations with the same official email, business number and full address. No existing unique constraints were weakened or removed.

The browser test creates one disposable personal account with a padded username and uppercase email. Registration succeeds with normalized details. Separate signup attempts using the existing email or a case variant of the username are rejected with the correct messages. See browser-results.json and duplicate-username.png.

Guarded cleanup removed only that synthetic account and verified that all other users and Stripe configuration were preserved. See cleanup-results.json. No real account was disabled, reactivated, merged or removed.

## Repeat

Run C:/xampp/php/php.exe vendor/phpunit/phpunit/phpunit --no-coverage tests/Integration/AccountIdentityPolicyTest.php.

For browser verification, set CARESYS_NODE_MODULES to the bundled node_modules directory, run node tests/Browser/account-identity-policy.cjs and then C:/xampp/php/php.exe tests/Browser/cleanup-identity-policy.php. The browser script refuses to overwrite an existing private fixture manifest. Always clean the prior fixture before running again.
