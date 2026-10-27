# Module pricing and automatic subscription invoices

Approved implementation baseline: 26 October 2026. Catalog version `2026-10-26-v1`. All CareSys amounts below are USD per month, before tax confirmation. Prices are introductory and can be changed for future requests in `app/Libraries/SubscriptionPricing.php`; issued invoice snapshots never recalculate.

## Monthly module rates

Custom platform: **$29/month**, including people administration and 10 active workers.

| Module | Monthly add-on |
|---|---:|
| Recruitment & applications | $20 |
| Consented career history | $5 |
| Offers & joining | $10 |
| People administration | Included |
| Scheduling & visits | $25 |
| Time & attendance | $15 |
| Leave management | $5 |
| Care notes & incidents | $15 |
| Equipment & custody | $5 |
| Expenses | $5 |
| Payroll preparation & exports | $15 |
| Invoices & receipts | $20 |
| Reporting | $10 |

History requires recruitment; scheduling requires people and care; payroll requires people and time. Dependencies are added once. A module already included in a package is never charged again.

## Packages and calculation

| Package | Monthly base | Included workers | Included modules |
|---|---:|---:|---|
| Custom | $29 | 10 | People |
| Starter | $59 | 10 | People, recruitment, offers & joining |
| Operations | $129 | 30 | Starter plus scheduling, time, leave, care |
| Growth | $249 | 75 | All 13 modules |

Workers beyond each package allowance cost **$3 per worker/month**. The worker selector covers 1–1,000 workers; declared coverage cannot be below the agency's current distinct active employed people. Administrative accounts do not independently add worker seats. Independent caregiver personal registration does not incur an agency subscription charge.

For every package: total = package base + required modules absent from that package + max(0, selected workers − included workers) × $3. The server chooses the lowest total among all four candidates. The user's preferred package breaks equal-price ties; otherwise a bundled package wins a tie against custom. A package may include additional modules beyond those requested; entitlement activation remains separately controlled.

| Selection | Workers | Selected result | Monthly subtotal |
|---|---:|---|---:|
| Scheduling (adds care dependency) | 10 | Custom | $69 |
| Recruitment and offers | 10 | Starter | $59 |
| Operations modules | 30 | Operations | $129 |
| Operations modules | 40 | Operations + 10 workers | $159 |
| All modules | 40 | Operations + $60 add-ons + 10 workers | $219 |
| All modules | 75 | Growth | $249 |

## Request and invoice workflow

1. Agency owner opens Subscription & trial (or Settings for an existing active agreement), selects modules, preferred package and worker coverage. Managed trials require a card verified through Stripe first.
2. Submission validates role, agency scope, CSRF, workspace nonce, options and current worker count. Prices are computed entirely on the server using integer cents.
3. A transaction saves a uniquely numbered **pro forma subscription invoice**, including the agency name, catalog version, requested and required modules, dependency additions, effective package modules, worker count, line items, comparisons and price-validity date (30 days).
4. Settings confirms issuance and lists up to 50 recent invoices with authenticated **Download PDF** links. The PDF is generated from the saved snapshot. Records remain stored beyond the displayed 50.
   The PDF includes an itemized charges table, monthly subtotal, worker coverage, validity date, page numbering and module descriptions. Scope identifies selected modules, required dependencies and package inclusions. New invoices save module labels and descriptions at issuance; older invoices use current catalog descriptions while preserving their original financial snapshot.
5. Repeating an identical request key returns the existing invoice; changing selections with an already-used key is rejected. A fresh form permits a new proposal. Invoice creation is audited and queues an internal outbox event; it does not send email automatically.

Pro forma proposals become marked paid only after Stripe confirms an owner-approved payment. Tax is confirmed at checkout when configured; the CareSys PDF is not a tax invoice. A verified-card agency's first module selection starts its configurable free trial and makes its selected package scope available during that period. At expiry, agency access locks until an owner approves and completes payment. No automatic recurring charge is created. Raw card details are collected only by Stripe. Subscription records remain isolated from client-care invoices, and PDF responses are private/no-store. See [the trial and payment workflow](Agency_Free_Trial_and_Payments.md) for configuration and API contracts.

## Market comparison used for introductory positioning

Public pricing researched 26 October 2026; verify vendor pages before a purchasing decision. Billing units and feature sets differ, so these are context rather than equivalent module quotes.

| Product | Public benchmark | Important distinction |
|---|---|---|
| [ShiftCare US](https://shiftcare.com/us/pricing) | $9 / $15 / $25 per user monthly; $8 / $13 / $20 annually | Minimum 5 users; user counts include administrators |
| [Connecteam](https://connecteam.com/pricing/) | Operations hub $35 / $59 / $119 monthly for first 30 users; $29 / $49 / $99 annually | Other hubs and their features may cost separately |
| [CareLineLive](https://carelinelive.com/pricing/) | £120 monthly minimum excluding VAT; eRoster £8.10/carer; BYOD £19.70/carer | GBP, different care scope; administrator accounts free |
| [CareSmartz360](https://www.caresmartz360.com/pricing/) | Quote based on active clients | No public dollar amount used for this comparison |

CareSys introductory prices are a product decision, not a claim of feature parity. Review operating costs, support workload, tax, payment fees and actual usage before commercial launch.
