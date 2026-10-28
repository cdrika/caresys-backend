# Multi agency scheduling validation 27 October 2026

The feature tests run against the isolated `caresys_test` MySQL database. No real card or payment provider is used. Browser checks use the existing fictional local demonstration accounts and perform an availability lookup only; they do not create or cancel local visits.

Final result: **63 tests and 284 assertions passed**, including 12 multi-agency acceptance tests and the independent cross-agency booking race test. All changed application PHP files passed syntax checks. The additive local database migration completed successfully. See `backend-tests.txt` and `browser-results.json` for evidence.

Run the backend suite with `C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --no-coverage`. Focused tests are in `tests/Acceptance/MultiAgencySchedulingTest.php` and `tests/Concurrency/ShiftRaceTest.php`.

Screenshots:

- `screenshots/owner-availability.png`: owner checks recorded availability and receives time choices.
- `screenshots/owner-availability-mobile.png`: the same web form at a 390 pixel viewport.
- `screenshots/caregiver-requests.png`: personal shift request page and empty state.

`browser-results.json` records the browser checks. Replacement approvals and failure paths are automated database tests, not claimed as browser screenshot evidence. The dated workflow guide under Documentation describes user actions, privacy, coverage notices and remaining limitations.
