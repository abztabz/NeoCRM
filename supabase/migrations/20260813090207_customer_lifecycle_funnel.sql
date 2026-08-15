alter table public.contacts drop constraint if exists contacts_status_check;
alter table public.contacts add constraint contacts_status_check
  check (status in ('new', 'contacted', 'qualified', 'customer', 'recurring', 'lost'));

create table public.customer_journeys (
  id uuid primary key default gen_random_uuid(),
  tenant_id uuid not null references public.tenants(id) on delete cascade,
  site_id uuid not null,
  visitor_id uuid,
  lead_id uuid,
  contact_id uuid,
  company_id uuid,
  deal_id uuid,
  stage text not null default 'visitor'
    check (stage in ('visitor', 'lead', 'contacted', 'qualified', 'proposal', 'negotiation', 'paying_client', 'recurring_client', 'lost')),
  source text not null default 'website' check (char_length(source) between 1 and 100),
  entry_method text not null default 'website'
    check (entry_method in ('website', 'manual', 'excel_import', 'upgrade', 'crm', 'api')),
  estimated_value numeric(18,2) not null default 0 check (estimated_value >= 0),
  recurring_value numeric(18,2) not null default 0 check (recurring_value >= 0),
  billing_interval text not null default ''
    check (billing_interval in ('', 'monthly', 'quarterly', 'annual')),
  owner_member_id uuid,
  stage_entered_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (tenant_id, id),
  unique (tenant_id, visitor_id),
  unique (tenant_id, lead_id),
  foreign key (tenant_id, site_id) references public.sites(tenant_id, id) on delete cascade,
  foreign key (tenant_id, site_id, visitor_id) references public.visitors(tenant_id, site_id, id) on delete set null (visitor_id),
  foreign key (tenant_id, lead_id) references public.leads(tenant_id, id) on delete set null (lead_id),
  foreign key (tenant_id, contact_id) references public.contacts(tenant_id, id) on delete set null (contact_id),
  foreign key (tenant_id, company_id) references public.companies(tenant_id, id) on delete set null (company_id),
  foreign key (tenant_id, deal_id) references public.deals(tenant_id, id) on delete set null (deal_id),
  foreign key (tenant_id, owner_member_id) references public.tenant_members(tenant_id, id) on delete set null (owner_member_id)
);

create index customer_journeys_tenant_stage_updated_idx
  on public.customer_journeys (tenant_id, stage, updated_at desc);
create index customer_journeys_site_idx
  on public.customer_journeys (tenant_id, site_id);
create index customer_journeys_visitor_scope_idx
  on public.customer_journeys (tenant_id, site_id, visitor_id);
create index customer_journeys_contact_idx
  on public.customer_journeys (tenant_id, contact_id);
create index customer_journeys_company_idx
  on public.customer_journeys (tenant_id, company_id);
create index customer_journeys_deal_idx
  on public.customer_journeys (tenant_id, deal_id);
create index customer_journeys_owner_idx
  on public.customer_journeys (tenant_id, owner_member_id);

create trigger customer_journeys_set_updated_at before update on public.customer_journeys
for each row execute function private.set_updated_at();

alter table public.customer_journeys enable row level security;

create policy customer_journeys_select_member on public.customer_journeys
for select to authenticated
using (private.is_tenant_member(tenant_id));

create policy customer_journeys_insert_sales on public.customer_journeys
for insert to authenticated
with check (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']));

create policy customer_journeys_update_sales on public.customer_journeys
for update to authenticated
using (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']))
with check (private.has_tenant_role(tenant_id, array['owner','admin','manager','sales']));

create policy customer_journeys_delete_manager on public.customer_journeys
for delete to authenticated
using (private.has_tenant_role(tenant_id, array['owner','admin','manager']));

grant select, insert, update, delete on public.customer_journeys to service_role;

insert into public.customer_journeys (
  tenant_id, site_id, visitor_id, stage, source, entry_method,
  stage_entered_at, created_at, updated_at
)
select
  v.tenant_id, v.site_id, v.id, 'visitor', 'website', 'upgrade',
  v.first_seen, v.first_seen, v.last_seen
from public.visitors v
on conflict (tenant_id, visitor_id) do nothing;

update public.customer_journeys j
set lead_id = l.id,
    stage = case l.status
      when 'working' then 'contacted'
      when 'qualified' then 'qualified'
      when 'disqualified' then 'lost'
      else 'lead'
    end,
    source = l.source,
    owner_member_id = l.owner_member_id,
    updated_at = l.updated_at
from public.leads l
where l.tenant_id = j.tenant_id
  and l.site_id = j.site_id
  and l.visitor_id = j.visitor_id
  and j.lead_id is null;

insert into public.customer_journeys (
  tenant_id, site_id, lead_id, stage, source, entry_method,
  owner_member_id, stage_entered_at, created_at, updated_at
)
select
  l.tenant_id, l.site_id, l.id,
  case l.status
    when 'working' then 'contacted'
    when 'qualified' then 'qualified'
    when 'disqualified' then 'lost'
    else 'lead'
  end,
  l.source, 'upgrade', l.owner_member_id,
  l.updated_at, l.created_at, l.updated_at
from public.leads l
where not exists (
  select 1 from public.customer_journeys j
  where j.tenant_id = l.tenant_id and j.lead_id = l.id
)
on conflict (tenant_id, lead_id) do nothing;

update public.customer_journeys j
set contact_id = c.id,
    company_id = c.company_id,
    stage = case c.status
      when 'customer' then 'paying_client'
      when 'recurring' then 'recurring_client'
      when 'lost' then 'lost'
      when 'qualified' then 'qualified'
      when 'contacted' then 'contacted'
      else j.stage
    end,
    updated_at = greatest(j.updated_at, c.updated_at)
from public.leads l
join public.contacts c
  on c.tenant_id = l.tenant_id and c.id = l.converted_contact_id
where j.tenant_id = l.tenant_id
  and j.lead_id = l.id
  and j.contact_id is null;

insert into public.customer_journeys (
  tenant_id, site_id, contact_id, company_id, stage, source, entry_method,
  owner_member_id, stage_entered_at, created_at, updated_at
)
select
  c.tenant_id, c.site_id, c.id, c.company_id,
  case c.status
    when 'customer' then 'paying_client'
    when 'recurring' then 'recurring_client'
    when 'lost' then 'lost'
    when 'qualified' then 'qualified'
    when 'contacted' then 'contacted'
    else 'lead'
  end,
  c.source, 'upgrade', c.owner_member_id,
  c.updated_at, c.created_at, c.updated_at
from public.contacts c
where not exists (
  select 1 from public.customer_journeys j
  where j.tenant_id = c.tenant_id and j.contact_id = c.id
);

with latest_deal as (
  select distinct on (tenant_id, contact_id)
    tenant_id, contact_id, id, company_id, amount, status, updated_at
  from public.deals
  where contact_id is not null
  order by tenant_id, contact_id, updated_at desc
)
update public.customer_journeys j
set deal_id = d.id,
    company_id = coalesce(j.company_id, d.company_id),
    estimated_value = d.amount,
    stage = case d.status
      when 'won' then 'paying_client'
      when 'lost' then 'lost'
      else j.stage
    end,
    updated_at = greatest(j.updated_at, d.updated_at)
from latest_deal d
where j.tenant_id = d.tenant_id
  and j.contact_id = d.contact_id
  and j.deal_id is null;
