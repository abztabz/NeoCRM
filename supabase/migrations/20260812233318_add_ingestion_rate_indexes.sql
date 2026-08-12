create index events_site_occurred_idx on public.events (site_id, occurred_at desc);
create index activities_site_created_idx on public.activities (site_id, created_at desc);

