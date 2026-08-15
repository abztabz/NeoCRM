import "jsr:@supabase/functions-js/edge-runtime.d.ts";
import { createClient } from "npm:@supabase/supabase-js@2.57.4";

const jsonHeaders = { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" };

function response(status: number, body: Record<string, unknown>, origin = "") {
  const headers: Record<string, string> = { ...jsonHeaders };
  if (origin) {
    headers["access-control-allow-origin"] = origin;
    headers["vary"] = "Origin";
  }
  return new Response(JSON.stringify(body), { status, headers });
}

function cleanText(value: unknown, limit: number) {
  return typeof value === "string" ? value.replace(/[\u0000-\u001f\u007f]/g, " ").trim().slice(0, limit) : "";
}

function cleanUrl(value: unknown) {
  try {
    const url = new URL(cleanText(value, 2048));
    if (!['http:', 'https:'].includes(url.protocol)) return null;
    url.search = "";
    url.hash = "";
    return url.toString().slice(0, 2048);
  } catch {
    return null;
  }
}

function validUuid(value: unknown): value is string {
  return typeof value === "string" && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

async function sha256(value: string) {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

Deno.serve(async (request: Request) => {
  const origin = request.headers.get("origin") ?? "";
  if (request.method !== "POST") return response(405, { error: "method_not_allowed" }, origin);

  const sitePublicId = request.headers.get("x-neocrm-site") ?? "";
  const siteToken = request.headers.get("x-neocrm-token") ?? "";
  if (!validUuid(sitePublicId) || siteToken.length < 32 || siteToken.length > 256) return response(401, { error: "invalid_site_credentials" }, origin);

  let payload: Record<string, unknown>;
  try {
    payload = await request.json();
  } catch {
    return response(400, { error: "invalid_json" }, origin);
  }

  const email = cleanText(payload.email, 190).toLowerCase();
  const firstName = cleanText(payload.first_name, 100);
  if (!firstName || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return response(400, { error: "invalid_lead" }, origin);
	const requestId = validUuid(payload.request_id) ? payload.request_id : crypto.randomUUID();

  const supabaseUrl = Deno.env.get("SUPABASE_URL");
  const serviceRoleKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");
  if (!supabaseUrl || !serviceRoleKey) return response(503, { error: "service_unavailable" }, origin);

  const db = createClient(supabaseUrl, serviceRoleKey, { auth: { persistSession: false, autoRefreshToken: false } });
  const { data: verified, error: verifyError } = await db.rpc("verify_site_token", {
    p_site_public_id: sitePublicId,
    p_token_hash: await sha256(siteToken),
  });
  if (verifyError || !verified?.length) return response(401, { error: "invalid_site_credentials" }, origin);

  const site = verified[0];
  const normalizedOrigin = origin.replace(/\/$/, "");
  const allowedOrigins = Array.isArray(site.allowed_origins) ? site.allowed_origins.map((item: string) => item.replace(/\/$/, "")) : [];
  if (!normalizedOrigin || !allowedOrigins.includes(normalizedOrigin)) return response(403, { error: "origin_not_allowed" }, origin);

  const rateWindow = new Date(Date.now() - 60_000).toISOString();
  const { count: recentLeads, error: rateError } = await db.from("activities").select("id", { count: "exact", head: true }).eq("site_id", site.site_id).eq("activity_type", "form_submission").gte("created_at", rateWindow);
  if (rateError) return response(503, { error: "rate_check_failed" }, origin);
  if ((recentLeads ?? 0) >= 30) return response(429, { error: "site_rate_limit" }, origin);

  const now = new Date().toISOString();
	const { data: duplicate, error: duplicateError } = await db.from("leads").select("id,visitor_id").eq("site_id", site.site_id).eq("request_id", requestId).maybeSingle();
	if (duplicateError) return response(503, { error: "lead_lookup_failed" }, origin);
	if (duplicate) {
		const journeyPayload = { tenant_id: site.tenant_id, site_id: site.site_id, visitor_id: duplicate.visitor_id, lead_id: duplicate.id, stage: "lead", source: "website_form", entry_method: "website" };
		const conflict = duplicate.visitor_id ? "tenant_id,visitor_id" : "tenant_id,lead_id";
		const { error: repairJourneyError } = await db.from("customer_journeys").upsert(journeyPayload, { onConflict: conflict });
		if (repairJourneyError) return response(503, { error: "journey_repair_failed" }, origin);
		const { data: existingActivity } = await db.from("activities").select("id").eq("tenant_id", site.tenant_id).eq("lead_id", duplicate.id).eq("activity_type", "form_submission").maybeSingle();
		if (!existingActivity) {
			const { error: repairActivityError } = await db.from("activities").insert({ tenant_id: site.tenant_id, site_id: site.site_id, lead_id: duplicate.id, visitor_id: duplicate.visitor_id, activity_type: "form_submission", subject: "Website enquiry received", body: null, metadata: { page_url: cleanUrl(payload.page_url) } });
			if (repairActivityError) return response(503, { error: "activity_repair_failed" }, origin);
		}
		return response(202, { accepted: true, duplicate: true, lead_id: duplicate.id }, origin);
	}

	let visitorId: string | null = null;
	let visitorScore = 0;
	if (validUuid(payload.visitor_uuid)) {
		const { data: visitor } = await db.from("visitors").select("id,engagement_score").eq("site_id", site.site_id).eq("visitor_uuid", payload.visitor_uuid).maybeSingle();
		if (visitor) {
			visitorId = visitor.id;
			visitorScore = Number(visitor.engagement_score) || 0;
		}
	}

	const { data: lead, error: leadError } = await db.from("leads").insert({
    tenant_id: site.tenant_id,
    site_id: site.site_id,
		visitor_id: visitorId,
		request_id: requestId,
    email,
    first_name: firstName,
    last_name: cleanText(payload.last_name, 100),
    phone: cleanText(payload.phone, 50),
		company_text: cleanText(payload.company, 190),
		message: cleanText(payload.message, 5000),
    source: "website_form",
		status: "new",
		identity_status: "unverified",
		marketing_opt_in_requested: payload.marketing_consent === true,
		score: visitorScore,
	}).select("id").single();
	if (leadError) return response(503, { error: "lead_create_failed" }, origin);
	if (visitorId) await db.from("visitors").update({ lead_id: lead.id, last_seen: now }).eq("id", visitorId);

  const { error: activityError } = await db.from("activities").insert({
    tenant_id: site.tenant_id,
    site_id: site.site_id,
		lead_id: lead.id,
    visitor_id: visitorId,
    activity_type: "form_submission",
    subject: "Website enquiry received",
		body: null,
    metadata: { page_url: cleanUrl(payload.page_url) },
  });
  if (activityError) return response(503, { error: "activity_create_failed" }, origin);

	const journeyPayload = { tenant_id: site.tenant_id, site_id: site.site_id, visitor_id: visitorId, lead_id: lead.id, stage: "lead", source: "website_form", entry_method: "website" };
	const conflict = visitorId ? "tenant_id,visitor_id" : "tenant_id,lead_id";
	const { error: journeyError } = await db.from("customer_journeys").upsert(journeyPayload, { onConflict: conflict });
	if (journeyError) return response(503, { error: "journey_create_failed" }, origin);

	return response(201, { accepted: true, lead_id: lead.id, identity_status: "unverified", visitor_linked: Boolean(visitorId) }, origin);
});
