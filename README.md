# NeoCRM

NeoCRM is the relationship layer of the living website: a professional WordPress CRM that begins with consented anonymous visitor activity and continues through lead identification and customer management.

## Architecture

```text
Website visitor
  → WordPress edge plugin
  → Vercel ingestion gateway
  → Supabase Edge Functions
  → tenant-isolated Postgres
```

The browser never receives a Supabase key or NeoCRM site token. WordPress forwards approved events using a site-scoped credential, and every cloud record carries a tenant and site boundary.

## Repository

- `wordpress-plugin/` — installable WordPress edge client, CRM interface, privacy tools, and local resilience layer
- `cloud/ingestion-api/` — Vercel gateway for events and leads
- `supabase/functions/` — authenticated origin-aware ingestion functions
- `supabase/migrations/` — reproducible multi-tenant schema and security policies
- `docs/` — architecture and deployment notes

## Current candidate

Release candidate 0.5 turns the WordPress plugin into an operational lifecycle CRM:

1. consented anonymous visitor → visitor journey
2. enquiry → separate unverified Lead
3. human qualification → Contact and Company
4. optional Deal → six-stage pipeline → won/lost
5. follow-up Task, notes, and activity history
6. unified funnel → paying client → recurring client

Identified relationships can enter at any stage. The Leads screen supports manual entry and native `.xlsx`/CSV bulk import with duplicate controls. Entering at Proposal or later creates the Contact, Company, and Deal records required for a usable sales history.

From Contacted onward, each journey can store monetary value, requirements, the next follow-up date/time, Email/Phone/WhatsApp communication medium, and stage-specific team notes with author and timestamp history.

Successful Elementor Pro contact/enquiry forms are captured natively when they contain a name, email, and message field. Existing Elementor email and post-submit actions continue unchanged; NeoCRM adds the Lead and attaches the consented visitor journey in the background.

The WordPress installation is the CRM authority in this release. Supabase remains the visitor-event cloud and the repository contains the hardened CRM schema plus safe Lead ingestion for the next cloud activation. Cloud CRM reads/writes stay disabled until per-human authentication, cloud export/erasure, and a reviewed production migration are active; the site tracking token is intentionally never reused as an admin credential.

Candidate status and release gates are governed by `docs/governance/neocrm-project-contract-0.5.md`, `docs/release-acceptance.md`, and the version-specific report in `docs/verification/`. A candidate is not an approved release until live WordPress evidence, independent verification, and Human Authority approval exist.

## Security boundary

- Row Level Security is enabled on every public CRM table.
- Supabase service credentials exist only in managed server runtimes.
- Site credentials are hashed in Supabase and encrypted at rest in WordPress.
- Registered origins are enforced after site-token authentication.
- Query strings and non-allowlisted event metadata are discarded.
- Site-level event and lead abuse limits are enforced in the database path.
- Public submissions are unverified Leads and cannot overwrite Contacts.
- Marketing requests are not treated as verified, sendable consent.
