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

## Current release

Release 0.2 proves both cloud journeys:

1. consented anonymous visitor → event → visitor profile
2. lead form → contact → anonymous journey linked to identified contact

The WordPress installation keeps a local operational mirror in this release. A later release will switch the admin interface to cloud reads and reduce local storage to a bounded outage buffer.

## Security boundary

- Row Level Security is enabled on every public CRM table.
- Supabase service credentials exist only in managed server runtimes.
- Site credentials are hashed in Supabase and encrypted at rest in WordPress.
- Registered origins are enforced after site-token authentication.
- Query strings and non-allowlisted event metadata are discarded.
- Site-level event and lead abuse limits are enforced in the database path.
