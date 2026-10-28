# Agency registration contact details

Agency registration requires legal name, country, official email, business phone, contact number, mobile number, currency and timezone. The eight required fields are marked with an asterisk. Business registration number is optional.

Phone inputs display examples and calling codes for the selected agency country. National numbers are parsed using that country; numbers starting with + can belong to another country. The server uses libphonenumber metadata to validate and normalize numbers to E164. Mobile entries must be mobile-capable. Extensions are not accepted. Email is syntax-validated, not externally verified.

New subscription invoice PDFs include official email, internationally formatted phone/contact/mobile numbers and optional business registration number. Client invoices and employment offers also capture agency contact snapshots; the Finance and offer screens display them. Existing issued documents keep their original snapshots and do not acquire invented contact information. The feature does not enable email delivery or Stripe.

The additive AgencyContactDetails migration retains existing agencies with empty defaults. Required contact validation applies to new registrations. The current release does not add an agency contact editing workflow.

Validation: 69 backend tests and 311 assertions passed, including registration rollback, optional registration number, required contacts, country-specific phone parsing, mobile validation and invoice snapshot stability. Browser checks cover required markers, UK and Australia phone hints and desktop/mobile layouts without registering a fictional agency. Both pages of a fictional invoice preview were visually checked.
