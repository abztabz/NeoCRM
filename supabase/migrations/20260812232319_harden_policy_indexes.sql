create index activities_actor_user_idx on public.activities (actor_user_id);
create index activities_site_idx on public.activities (site_id);
create index activities_tenant_idx on public.activities (tenant_id);
create index activities_visitor_idx on public.activities (visitor_id);
create index contacts_owner_user_idx on public.contacts (owner_user_id);
create index contacts_site_idx on public.contacts (site_id);
create index events_session_idx on public.events (session_id);
create index sessions_tenant_idx on public.sessions (tenant_id);
create index tenant_members_user_idx on public.tenant_members (user_id);
create index visitors_contact_idx on public.visitors (contact_id);

drop policy if exists sites_select_member on public.sites;
drop policy if exists sites_manage_admin on public.sites;
create policy sites_select_member on public.sites
for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy sites_insert_admin on public.sites
for insert to authenticated
with check (private.has_tenant_role(tenant_id, array['owner', 'admin']));
create policy sites_update_admin on public.sites
for update to authenticated
using (private.has_tenant_role(tenant_id, array['owner', 'admin']))
with check (private.has_tenant_role(tenant_id, array['owner', 'admin']));
create policy sites_delete_admin on public.sites
for delete to authenticated
using (private.has_tenant_role(tenant_id, array['owner', 'admin']));

drop policy if exists contacts_select_member on public.contacts;
drop policy if exists contacts_manage_sales on public.contacts;
create policy contacts_select_member on public.contacts
for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy contacts_insert_sales on public.contacts
for insert to authenticated
with check (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']));
create policy contacts_update_sales on public.contacts
for update to authenticated
using (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']))
with check (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']));
create policy contacts_delete_sales on public.contacts
for delete to authenticated
using (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']));

drop policy if exists activities_select_member on public.activities;
drop policy if exists activities_manage_sales on public.activities;
create policy activities_select_member on public.activities
for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy activities_insert_sales on public.activities
for insert to authenticated
with check (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']));
create policy activities_update_sales on public.activities
for update to authenticated
using (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']))
with check (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']));
create policy activities_delete_sales on public.activities
for delete to authenticated
using (private.has_tenant_role(tenant_id, array['owner', 'admin', 'manager', 'sales']));

