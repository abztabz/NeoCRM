# NeoCRM Project Contract

## Identity

- Project ID: NEOCRM
- Contract version: 1.0.0
- Candidate: 0.5.0-rc.1
- Owner and Human Authority: Founder
- Product lead: Morpheus
- Technical custodian and builder: Atlas
- Security reviewer: Aegis
- Independent verifier: NeoOS QA Council or a verifier who did not implement the candidate

## Mission and outcome

NeoCRM is the relationship layer of the living website. It must preserve a continuous, consent-aware journey from anonymous visitor through Lead, Contacted, Qualified, Proposal, Negotiation, Paying Client, Recurring Client, or Lost. Identified relationships may enter at any non-visitor stage.

The 0.5 outcome is a usable local WordPress sales workspace with visitor intelligence, safe Lead capture, manual and spreadsheet intake, lifecycle movement, monetary value, requirements, follow-up scheduling, communication medium, and stage-specific team history.

## Approved artifacts

| Artifact ID | Version | Authority | Status |
|---|---:|---|---|
| NEOCRM-CONTRACT | 1.0.0 | Founder | Candidate; approval pending |
| NEOCRM-PLUGIN | 0.5.0-rc.1 | Founder | Implemented; live verification pending |
| NEOCRM-CLOUD-SCHEMA | 0.5 candidate | Founder | Implemented in repository; production activation prohibited |
| NEOCRM-ACCEPTANCE | 1.0.0 | Atlas | Specified |

## Execution mode

Production candidate. WordPress local CRM operation is in scope. Human cloud CRM access and cloud CRM authority are not authorized for production activation.

## Scope

### Included

- consented first-party visitor and session tracking;
- Elementor Pro and native form conversion into unverified Leads;
- manual Lead entry and XLSX/CSV import;
- visitor-to-recurring-client lifecycle funnel;
- Contacts, Companies, Deals, Tasks, notes, activity and journey history;
- monetary value, requirements, next follow-up and Email/Phone/WhatsApp medium from Contacted onward;
- WordPress capabilities, nonces, data export/erasure and retention controls;
- optional cloud visitor-event ingestion behind the existing site credential boundary.

### Excluded

- cloud CRM reads or human CRM CRUD;
- using the site ingestion token as an administrator credential;
- verified marketing consent from public form submission alone;
- automated outbound email, phone or WhatsApp messaging;
- meeting recording, transcription or AI-generated minutes;
- forecasting, custom fields and marketing automation execution.

## Delegated authority

### Permitted

- implement and test requirements inside the repository;
- add backward-compatible WordPress database migrations;
- create release-candidate ZIPs and draft pull requests;
- fix defects discovered by verification without changing approved product intent.

### Prohibited

- deploy cloud schema or functions to production without the migration gate;
- send messages, create calendar events, record meetings or process payments without explicit customer configuration and authorization;
- claim independent verification from builder-only checks;
- delete or rewrite customer CRM records during upgrade;
- expose site tokens, Supabase service credentials or private form data to browser JavaScript.

## Budget and resources

Prefer WordPress-native capabilities and current Vercel/Supabase infrastructure. New paid providers require Human Authority approval. Free APIs are candidates only after provenance, privacy, reliability and maintenance review.

## Risk boundaries

- Public submissions remain unverified Leads and may not overwrite canonical Contacts.
- Marketing requests remain unverified until a separate confirmation flow exists.
- Cloud CRM remains disabled until per-human authentication, exact tenant authorization, cloud privacy operations and independently reviewed migrations exist.
- Meeting recordings and transcripts are high-sensitivity records and require retention, access, consent and deletion controls before implementation.

## Exact requirements

- A consented visitor is recorded without exposing a server credential.
- A successful configured form creates one Lead and attaches the available visitor journey.
- A Lead can move through the complete lifecycle or enter at an identified stage.
- From Contacted onward, a journey can store value, requirements, follow-up time and communication medium.
- Team notes retain stage, author and timestamp history.
- Existing data survives plugin upgrade.
- Every mutation requires the appropriate WordPress capability and nonce.

## Zero-tolerance requirements

- no credential disclosure;
- no cross-tenant cloud access;
- no false marketing-consent grant;
- no successful response after a failed critical database write;
- no destructive upgrade or privacy callback that reports a false outcome;
- no production cloud CRM activation before its explicit release gate.

## Low-tolerance requirements

- responsive usability at 390 px and desktop widths;
- accessible labels, focus states and keyboard-operable disclosure controls;
- bounded public payloads and imports;
- visible, accurate success and error states.

## Evidence requirements

- commit SHA and immutable candidate ZIP checksum;
- CI logs for PHP syntax, JavaScript syntax, secret scanning and ZIP integrity;
- WordPress fresh-install and upgrade evidence;
- acceptance-test record for the full visitor-to-recurring journey;
- mobile and desktop screenshots;
- independent verification report tied to the candidate commit.

## Acceptance tests

The binding test plan is `docs/release-acceptance.md`.

## Escalation triggers

- production credential, customer-data or privacy-policy changes;
- destructive migration or rollback;
- paid provider or material recurring cost;
- meeting recording/transcription activation;
- inability to preserve existing WordPress data;
- disagreement between candidate behavior and this contract.

## Rollback plan

Preserve the prior approved ZIP and database backup. If an upgrade fails, deactivate the candidate, restore the prior plugin version, and restore the pre-upgrade database only when the migration changed data incompatibly. Cloud migrations require a separately reviewed rollback or forward-fix plan before deployment.

## Human approval gates

- approve this contract as the binding NeoCRM baseline;
- approve a candidate only after independent verification;
- separately approve cloud CRM activation;
- separately approve meeting recording/transcription and outbound communication providers.

## Expiry and review point

Review at the next material architecture change, before cloud CRM activation, or before implementing meeting records—whichever occurs first.
