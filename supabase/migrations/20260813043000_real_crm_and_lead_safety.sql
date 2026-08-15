alter default privileges in schema public revoke all on tables from anon, authenticated;
alter default privileges in schema public revoke all on sequences from anon, authenticated;

revoke all on all tables in schema public from anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;

alter table public.tenant_members
  add column if not exists status text not null default 'active'
  check (status in ('active', 'invited', 'suspended', 'removed'));

alter table public.tenant_members add constraint tenant_members_tenant_id_id_key unique (tenant_id, id);
alter table public.sites add constraint sites_tenant_id_id_key unique (tenant_id, id);
alter table public.contacts add constraint contacts_tenant_id_id_key unique (tenant_id, id);
alter table public.visitors add constraint visitors_tenant_site_id_key unique (tenant_id, site_id, id);

create table public.leads (
  id uuid primary key default gen_random_uuid(),
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  site_id uuid not null,
  visitor_id uuid,
  request_id uuid not null,
  email extensions.citext not null,
  first_name text not null default '' check (char_length(first_name) <= 100),
  last_name text not null default '' check (char_length(last_name) <= 100),
  phone text not null default '' check (char_length(phone) <= 50),
  company_text text not null default '' check (char_length(company_text) <= 190),
  message text not null default '' check (char_length(message) <= 5000),
  status text not null default 'new' check (status in ('new', 'working', 'qualified', 'disqualified', 'converted')),
  identity_status text not null default 'unverified' check (identity_status in ('unverified', 'verified', 'rejected')),
  marketing_opt_in_requested boolean not null default false,
  source text not null default 'website_form' check (char_length(source) <= 100),
  score integer not null default 0 check (score between 0 and 100),
  owner_member_id uuid,
  converted_contact_id uuid,
  converted_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (site_id, request_id),
  unique (tenant_id, id),
  foreign key (tenant_id, site_id) references public.sites(tenant_id, id) on delete cascade,
  foreign key (tenant_id, site_id, visitor_id) references public.visitors(tenant_id, site_id, id) on delete set null (visitor_id),
  foreign key (tenant_id, owner_member_id) references public.tenant_members(tenant_id, id) on delete set null (owner_member_id),
  foreign key (tenant_id, converted_contact_id) references public.contacts(tenant_id, id) on delete set null (converted_contact_id)
);

alter table public.visitors add column if not exists lead_id uuid;
alter table public.visitors add constraint visitors_tenant_lead_fkey
  foreign key (tenant_id, lead_id) references public.leads(tenant_id, id) on delete set null (lead_id);

create table public.companies (
  id uuid primary key default gen_random_uuid(),
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  name text not null check (char_length(name) between 1 and 190),
  domain text not null default '' check (char_length(domain) <= 190),
  phone text not null default '' check (char_length(phone) <= 50),
  website text,
  industry text not null default '' check (char_length(industry) <= 120),
  owner_member_id uuid,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  deleted_at timestamptz,
  unique (tenant_id, id),
  unique (tenant_id, name),
  foreign key (tenant_id, owner_member_id) references public.tenant_members(tenant_id, id) on delete set null (owner_member_id)
);

alter table public.contacts add column if not exists company_id uuid;
alter table public.contacts add column if not exists owner_member_id uuid;
alter table public.contacts add column if not exists deleted_at timestamptz;
alter table public.contacts add constraint contacts_tenant_company_fkey
  foreign key (tenant_id, company_id) references public.companies(tenant_id, id) on delete set null (company_id);
alter table public.contacts add constraint contacts_tenant_owner_member_fkey
  foreign key (tenant_id, owner_member_id) references public.tenant_members(tenant_id, id) on delete set null (owner_member_id);

create table public.pipelines (
  id uuid primary key default gen_random_uuid(),
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  name text not null check (char_length(name) between 1 and 190),
	"is_default" boolean not null default false,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (tenant_id, id),
  unique (tenant_id, name)
);

create table public.pipeline_stages (
  id uuid primary key default gen_random_uuid(),
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  pipeline_id uuid not null,
  name text not null check (char_length(name) between 1 and 120),
  position integer not null default 0 check (position >= 0),
  probability integer not null default 0 check (probability between 0 and 100),
  color text not null default '#3157d5' check (color ~ '^#[0-9A-Fa-f]{6}$'),
  is_closed boolean not null default false,
  is_won boolean not null default false,
  unique (tenant_id, id),
  unique (tenant_id, pipeline_id, id),
  unique (pipeline_id, position),
  foreign key (tenant_id, pipeline_id) references public.pipelines(tenant_id, id) on delete cascade
);

create table public.deals (
  id uuid primary key default gen_random_uuid(),
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  pipeline_id uuid not null,
  stage_id uuid not null,
  contact_id uuid,
  company_id uuid,
  name text not null check (char_length(name) between 1 and 190),
  amount numeric(18,2) not null default 0 check (amount >= 0),
  currency text not null default 'AED' check (currency ~ '^[A-Z]{3}$'),
  probability integer not null default 0 check (probability between 0 and 100),
  status text not null default 'open' check (status in ('open', 'won', 'lost')),
  expected_close_date date,
  owner_member_id uuid,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  deleted_at timestamptz,
  unique (tenant_id, id),
  foreign key (tenant_id, pipeline_id) references public.pipelines(tenant_id, id),
  foreign key (tenant_id, pipeline_id, stage_id) references public.pipeline_stages(tenant_id, pipeline_id, id),
  foreign key (tenant_id, contact_id) references public.contacts(tenant_id, id) on delete set null (contact_id),
  foreign key (tenant_id, company_id) references public.companies(tenant_id, id) on delete set null (company_id),
  foreign key (tenant_id, owner_member_id) references public.tenant_members(tenant_id, id) on delete set null (owner_member_id)
);

create table public.tasks (
  id uuid primary key default gen_random_uuid(),
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  contact_id uuid,
  company_id uuid,
  deal_id uuid,
  title text not null check (char_length(title) between 1 and 190),
  description text not null default '' check (char_length(description) <= 5000),
  status text not null default 'open' check (status in ('open', 'completed', 'cancelled')),
  priority text not null default 'normal' check (priority in ('low', 'normal', 'high')),
  due_at timestamptz,
  owner_member_id uuid,
  completed_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (tenant_id, id),
  foreign key (tenant_id, contact_id) references public.contacts(tenant_id, id) on delete set null (contact_id),
  foreign key (tenant_id, company_id) references public.companies(tenant_id, id) on delete set null (company_id),
  foreign key (tenant_id, deal_id) references public.deals(tenant_id, id) on delete set null (deal_id),
  foreign key (tenant_id, owner_member_id) references public.tenant_members(tenant_id, id) on delete set null (owner_member_id)
);

create table public.notes (
  id bigint generated by default as identity primary key,
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  contact_id uuid,
  company_id uuid,
  deal_id uuid,
  body text not null check (char_length(body) between 1 and 10000),
  author_member_id uuid not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  foreign key (tenant_id, contact_id) references public.contacts(tenant_id, id) on delete cascade,
  foreign key (tenant_id, company_id) references public.companies(tenant_id, id) on delete cascade,
  foreign key (tenant_id, deal_id) references public.deals(tenant_id, id) on delete cascade,
  foreign key (tenant_id, author_member_id) references public.tenant_members(tenant_id, id)
);

alter table public.activities add column if not exists lead_id uuid;
alter table public.activities add column if not exists company_id uuid;
alter table public.activities add column if not exists deal_id uuid;
alter table public.activities add column if not exists task_id uuid;

create or replace function private.is_tenant_member(p_tenant_id uuid)
returns boolean
language sql
security definer
stable
set search_path = ''
as $$
  select exists (
    select 1
    from public.tenant_members tm
    join public.tenants t on t.id = tm.tenant_id
    where tm.tenant_id = p_tenant_id
      and tm.user_id = (select auth.uid())
      and tm.status = 'active'
      and t.status = 'active'
  );
$$;

create or replace function private.has_tenant_role(p_tenant_id uuid, p_roles text[])
returns boolean
language sql
security definer
stable
set search_path = ''
as $$
  select exists (
    select 1
    from public.tenant_members tm
    join public.tenants t on t.id = tm.tenant_id
    where tm.tenant_id = p_tenant_id
      and tm.user_id = (select auth.uid())
      and tm.status = 'active'
      and t.status = 'active'
      and tm.role = any (p_roles)
  );
$$;

create index leads_tenant_status_updated_idx on public.leads (tenant_id, status, updated_at desc);
create index leads_site_created_idx on public.leads (site_id, created_at desc);
create index companies_tenant_name_idx on public.companies (tenant_id, name);
create index deals_tenant_stage_updated_idx on public.deals (tenant_id, stage_id, updated_at desc);
create index tasks_tenant_status_due_idx on public.tasks (tenant_id, status, due_at);
create index notes_contact_created_idx on public.notes (contact_id, created_at desc);

create trigger leads_set_updated_at before update on public.leads
for each row execute function private.set_updated_at();
create trigger companies_set_updated_at before update on public.companies
for each row execute function private.set_updated_at();
create trigger pipelines_set_updated_at before update on public.pipelines
for each row execute function private.set_updated_at();
create trigger deals_set_updated_at before update on public.deals
for each row execute function private.set_updated_at();
create trigger tasks_set_updated_at before update on public.tasks
for each row execute function private.set_updated_at();
create trigger notes_set_updated_at before update on public.notes
for each row execute function private.set_updated_at();

alter table public.leads enable row level security;
alter table public.companies enable row level security;
alter table public.pipelines enable row level security;
alter table public.pipeline_stages enable row level security;
alter table public.deals enable row level security;
alter table public.tasks enable row level security;
alter table public.notes enable row level security;

create policy leads_select_member on public.leads for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy companies_select_member on public.companies for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy pipelines_select_member on public.pipelines for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy pipeline_stages_select_member on public.pipeline_stages for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy deals_select_member on public.deals for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy tasks_select_member on public.tasks for select to authenticated
using (private.is_tenant_member(tenant_id));
create policy notes_select_member on public.notes for select to authenticated
using (private.is_tenant_member(tenant_id));

create policy leads_manage_sales on public.leads for all to authenticated
using (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']))
with check (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']));
create policy companies_manage_sales on public.companies for all to authenticated
using (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']))
with check (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']));
create policy deals_manage_sales on public.deals for all to authenticated
using (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']))
with check (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']));
create policy tasks_manage_sales on public.tasks for all to authenticated
using (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']))
with check (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']));
create policy notes_insert_sales on public.notes for insert to authenticated
with check (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']));

grant usage on schema public to service_role;
grant select, insert, update, delete on public.leads, public.companies, public.pipelines,
  public.pipeline_stages, public.deals, public.tasks, public.notes to service_role;
grant usage, select on all sequences in schema public to service_role;

insert into public.pipelines (tenant_id, name, "is_default")
select id, 'Sales Pipeline', true from public.tenants
on conflict (tenant_id, name) do nothing;

insert into public.pipeline_stages (tenant_id, pipeline_id, name, position, probability, color, is_closed, is_won)
select p.tenant_id, p.id, s.name, s.position, s.probability, s.color, s.is_closed, s.is_won
from public.pipelines p
cross join (values
  ('New', 0, 10, '#64748b', false, false),
  ('Qualified', 1, 25, '#3157d5', false, false),
  ('Proposal', 2, 50, '#7c3aed', false, false),
  ('Negotiation', 3, 75, '#d97706', false, false),
  ('Won', 4, 100, '#07834a', true, true),
  ('Lost', 5, 0, '#b42318', true, false)
) as s(name, position, probability, color, is_closed, is_won)
on conflict (pipeline_id, position) do nothing;
