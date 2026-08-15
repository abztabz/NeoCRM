# NeoCRM ingestion API

Vercel gateway for WordPress-to-NeoCRM event ingestion. It applies request-size and timeout boundaries, forwards site-scoped credentials, and never contains a Supabase service-role key.

The v0.5 release candidate also contains the server-side NeoOS Source Registry consumer. Registry access is intentionally kept out of the WordPress plugin, uses a dedicated `neocrm` consumer identity, and is limited to bounded public-data enrichment capabilities with CRM PII rejected before external requests.
