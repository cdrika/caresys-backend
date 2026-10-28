# Agency registration contact details

Agency registration requires legal name, country, official email, currency and timezone. These five base fields are marked with an asterisk. Business registration number and all phone numbers are optional. Phone inputs become required only when the corresponding Add number option is selected.

Phone inputs display examples and calling codes for the selected agency country. National numbers are parsed using that country; numbers starting with + can belong to another country. The server uses libphonenumber metadata to validate and normalize numbers to E164. Mobile entries must be mobile-capable. Extensions are not accepted. Email is syntax-validated, not externally verified.

New subscription invoice PDFs include official email, internationally formatted phone/contact/mobile numbers and optional business registration number. Client invoices and employment offers also capture agency contact snapshots; the Finance and offer screens display them. Existing issued documents keep their original snapshots and do not acquire invented contact information. The feature does not enable email delivery or Stripe.

The additive AgencyContactDetails migration retains existing agencies with empty defaults. Required contact validation applies to new registrations. The current release does not add an agency contact editing workflow.

Validation: 78 backend tests and 351 assertions passed, including registration rollback, optional registration number, required official email, optional/shared phone controls, country-specific phone parsing, mobile validation and invoice snapshot stability, address opt-in/out, conditional address validation and country-specific postal formats. Browser checks cover required markers, UK and Australia phone hints, US/Canada/UAE address fields, and desktop/mobile layouts without registering a fictional agency. Both pages of a fictional invoice preview were visually checked.

## Optional address

The agency can opt in with Enter agency address. Address fields stay hidden and disabled while opted out, and the server ignores submitted address values unless include_address=yes. Address line 1 is required only after opting in; line 2 is optional. City/locality, state/province/county and postal/ZIP requirements follow the selected country metadata. Known states and provinces are selected from a list. Countries without postal codes do not display or store a postal code. Country changes clear incompatible state choices.

Addresses are formatted in country order and captured in new invoice and offer snapshots. Existing issued documents remain unchanged. Postal validation checks country format, not deliverability or geocoded address accuracy. The address is not requested or required to register without opting in.

## Optional and shared phone numbers

Select Add a business number to enter a main number. Check This is also my mobile number and/or This is also my contact number to reuse it. Separate fields are hidden and excluded from submission when shared. If a number differs, select Add a different mobile/contact number and enter it once. A separate mobile or contact number can also be added without a business number. Unselected inputs are ignored by the server. Declaring a business number as mobile requires mobile-capable validation. Saved canonical numbers continue to appear in invoice and offer contact snapshots.
