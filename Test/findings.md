# Findings and corrections

## F-01 — Payment button enabled while Stripe was disabled

**Observed:** After the test agency's trial expired, the owner saw the correct payment prompt, but the **Approve payment on Stripe** button was enabled even though the server reported `stripe_configured=false`.

**Impact:** An owner could click an action that could not complete. The server remained fail-closed: missing Stripe credentials prevent provider operations and do not unlock access or collect money. This was a UI availability inconsistency, not a successful payment bypass.

**Correction:** The application billing page now disables payment approval when Stripe is unconfigured, matching the existing card-setup button and mobile client behavior. Server payment validation is unchanged.

**Source:** `app/Views/caresys/billing.php`.

**Verification:** The scenario asserts that the expired owner's payment prompt exists and its approval button is disabled while Stripe remains unconfigured. See the final successful `results.json` and `screenshots/08-owner-expired-payment-prompt.png`.

## Test-harness adjustments

Initial setup attempts used an underscored username and treated custom country/currency controls as text inputs. Registration correctly rejected the invalid account-name format. The runner was corrected to use periods in usernames and select or retain valid dropdown values. The newly registered accounts were reused on subsequent runs; product validation rules were not weakened.

The final scenario results supersede these interrupted setup attempts. Simulated state is reset after each interrupted scenario and after the successful run.
