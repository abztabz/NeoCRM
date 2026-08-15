# NeoCRM 0.3 — operational CRM

## Acceptance workflow

1. A visitor grants analytics permission and NeoCRM records the journey.
2. The enquiry form creates a new, unverified Lead without modifying a Contact.
3. A WordPress administrator qualifies or disqualifies the Lead.
4. Conversion creates or safely reuses a Contact, creates/reuses a Company, and can create a Deal.
5. The Deal moves through New, Qualified, Proposal, Negotiation, Won, or Lost.
6. Team members create and complete follow-up Tasks and add notes to the Contact workspace.

## Trust boundary

- WordPress roles and nonces authorize CRM mutations in 0.3.
- NeoCRM Cloud accepts visitor events through a site-scoped collection token.
- Lead forwarding is disabled in the plugin until the hardened cloud migration and safe Lead function are deployed.
- The site collection token is not an admin credential and cannot authorize CRM reads.
- A form checkbox records only an opt-in request; it does not create sendable marketing consent without verification.

## Prepared cloud activation

`supabase/migrations/20260813043000_real_crm_and_lead_safety.sql` adds tenant-safe Leads, Companies, Pipelines, Stages, Deals, Tasks, and Notes; removes broad anonymous/authenticated database privileges; and seeds a default pipeline. The updated `ingest-leads` function writes unverified Leads instead of canonical Contacts.

Production application requires an explicit maintenance approval because privilege revocation changes the live database access boundary. Deploy the migration before deploying the updated Lead function.
