# NeoCRM Release Acceptance Plan

Record the candidate commit, WordPress/PHP versions, browser, viewport, tester and evidence link for every run.

## Automated gates

- [ ] Every PHP file passes `php -l` on PHP 8.0.
- [ ] Vercel gateway JavaScript passes its syntax checks.
- [ ] Tracker JavaScript passes `node --check`.
- [ ] No private key, service-role key, bearer token or obvious credential is committed.
- [ ] The installable ZIP contains one `neo-crm/` root and passes archive integrity testing.
- [ ] Plugin header, constant and readme stable tag use the same version.

## Fresh installation

- [ ] Activate on a clean supported WordPress installation without warnings or fatal errors.
- [ ] Required tables, capabilities, default pipeline and settings are created.
- [ ] Every NeoCRM admin screen opens for an administrator.
- [ ] Unauthorized users cannot view or mutate CRM records.

## Upgrade and rollback

- [ ] Back up the database and install the last approved release with representative data.
- [ ] Upgrade to the candidate without losing Visitors, Leads, Contacts, Deals, Tasks, notes or events.
- [ ] New lifecycle and journey-update fields/tables are created once and preserve existing values.
- [ ] Deactivation does not delete CRM data.
- [ ] Prior ZIP and backup provide a documented rollback path.

## Visitor and Lead journey

- [ ] A consented first page view creates the Visitor journey.
- [ ] A configured Elementor Pro form continues its original actions and creates one unverified Lead.
- [ ] The native shortcode form creates one unverified Lead.
- [ ] Repeated or malformed submissions do not overwrite a canonical Contact or grant marketing consent.
- [ ] Visitor history remains attached after Lead conversion.

## Sales workflow

- [ ] Manual Lead entry works at each allowed starting stage.
- [ ] CSV and XLSX imports enforce size/row limits and duplicate policy.
- [ ] A relationship moves through Contacted, Qualified, Proposal, Negotiation, Paying Client and Recurring Client.
- [ ] Required Contact, Company and Deal records are created without duplicate or inconsistent links.
- [ ] Monetary value synchronizes to the linked Deal.
- [ ] Requirements, next follow-up and communication medium persist from Contacted onward.
- [ ] Team notes preserve stage, author, timestamp and chronological history.
- [ ] Lost and restored journeys remain auditable.

## Privacy and security

- [ ] Every administrator mutation rejects missing/invalid nonces and insufficient capabilities.
- [ ] Public payload and import limits are enforced.
- [ ] WordPress export includes identifiable Lead, journey and activity data.
- [ ] Erasure accurately deletes or reports retained related records.
- [ ] Invalid cloud endpoints never receive a site credential.
- [ ] Cloud CRM reads/writes remain disabled.

## Responsive and accessibility

- [ ] Test at 390 px, 768 px and at least 1280 px.
- [ ] Funnel columns scroll intentionally without trapping the page.
- [ ] Intake and journey editors are collapsible and keyboard operable.
- [ ] Labels, focus indicators, errors and controls remain readable at mobile width.

## Release decision

Only an independent verifier may mark the candidate Pass or Conditional Pass. A failed zero-tolerance check blocks release.
