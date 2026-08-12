# NeoCRM Cloud Foundation

## Live resources

- Supabase project: **NeoCRM Cloud** (`ap-south-1`)
- Supabase project reference: `myxywrsumtcnafeidshp`
- Vercel project: **neocrm-ingestion**
- Production gateway: `https://neocrm-ingestion.vercel.app`
- Events: `POST /api/v1/events`
- Leads: `POST /api/v1/leads`
- Health: `GET /api/health`

No secret credential is committed to this repository.

## Tenant model

Every business is a tenant. A tenant may own multiple registered websites. Visitors, sessions, events, contacts, and activities carry `tenant_id`; website-generated data also carries `site_id`.

Authenticated CRM users obtain access through `tenant_members`. RLS policies call private membership and role helpers, preventing a user in one business from reading or changing another business's records.

## Site authentication

Each website receives:

- a public site UUID
- a high-entropy site token

Only the SHA-256 token hash is stored in Supabase. The plaintext token is encrypted in WordPress using keys derived from WordPress security salts. It is never embedded in tracker JavaScript.

## Data minimisation

- URL query strings and fragments are removed.
- Event metadata is limited to `href`, `depth`, `seconds`, `intent_category`, and `language`.
- Raw IP addresses are not stored in NeoCRM Cloud.
- Marketing consent is recorded separately from analytics consent.
- Public collection endpoints verify both credential and registered origin.

## Operational status

The positive event and lead paths were verified through the production Vercel alias. Invalid-origin requests were rejected with HTTP 403. Synthetic verification data was removed after testing.

## Remaining product work

- Cloud read API for the WordPress dashboard
- Durable outage queue with retry and dead-letter handling
- Control-plane onboarding and automatic site-token rotation
- Contact detail, company, deals, tasks, and automation modules
- Retention jobs for cloud event data

