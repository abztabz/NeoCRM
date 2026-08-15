# NeoCRM 0.4 — lifecycle funnel and Lead import

## Acceptance workflow

1. A consented visitor appears automatically in the Visitor stage.
2. A website enquiry advances the same journey to Lead.
3. A team member can move the journey through Contacted and Qualified.
4. Proposal and Negotiation stages create or synchronize the Contact, Company, and Deal.
5. Won work becomes a Paying Client and can then advance to Recurring Client with a recurring value and billing interval.
6. Lost remains an explicit exit stage and can be reversed by an authorized team member.

Identified people may start at any stage through manual Lead entry or spreadsheet import. Later-stage entries retain the originating Lead and create the downstream CRM records automatically.

## Spreadsheet contract

NeoCRM accepts `.xlsx` and `.csv` files up to 5 MB and processes at most 1,000 data rows per upload. Required columns are `first_name` and `email`. Supported optional columns are `last_name`, `phone`, `company`, `stage`, `source`, `estimated_value`, `recurring_value`, and `billing_interval`.

Duplicate email handling is explicit: skip the row or update the existing open Lead/Contact. Each import returns created, updated, skipped, and invalid counts.

## Cloud activation order

The WordPress plugin is operational with its local database after upgrade. Cloud lifecycle collection additionally requires `20260813090207_customer_lifecycle_funnel.sql`, followed by the updated `ingest-events` and `ingest-leads` functions. Do not deploy those functions before the migration.
