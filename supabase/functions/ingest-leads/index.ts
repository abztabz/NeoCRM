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

  const marketingConsent = payload.marketing_consent === true;
  const now = new Date().toISOString();
  const { data: existingContact, error: contactReadError } = await db.from("contacts").select("id,marketing_consent,consent_recorded_at").eq("tenant_id", site.tenant_id).eq("email", email).maybeSingle();
  if (contactReadError) return response(503, { error: "contact_lookup_failed" }, origin);

  const contactData = {
    tenant_id: site.tenant_id,
    site_id: site.site_id,
    email,
    first_name: firstName,
    last_name: cleanText(payload.last_name, 100),
    phone: cleanText(payload.phone, 50),
    company: cleanText(payload.company, 190),
    source: "website_form",
    marketing_consent: marketingConsent || Boolean(existingContact?.marketing_consent),
    consent_recorded_at: marketingConsent ? now : existingContact?.consent_recorded_at ?? null,
  };

  let contactId = existingContact?.id;
  if (contactId) {
    const { error } = await db.from("contacts").update(contactData).eq("id", contactId);
    if (error) return response(503, { error: "contact_update_failed" }, origin);
  } else {
    const { data, error } = await db.from("contacts").insert(contactData).select("id").single();
    if (error) return response(503, { error: "contact_create_failed" }, origin);
    contactId = data.id;
  }

  let visitorId: string | null = null;
  if (validUuid(payload.visitor_uuid)) {
    const { data: visitor } = await db.from("visitors").select("id").eq("site_id", site.site_id).eq("visitor_uuid", payload.visitor_uuid).maybeSingle();
    if (visitor) {
      visitorId = visitor.id;
      await db.from("visitors").update({ contact_id: contactId, engagement_score: 100, last_seen: now }).eq("id", visitor.id);
    }
  }

  const { error: activityError } = await db.from("activities").insert({
    tenant_id: site.tenant_id,
    site_id: site.site_id,
    contact_id: contactId,
    visitor_id: visitorId,
    activity_type: "form_submission",
    subject: "Website enquiry received",
    body: cleanText(payload.message, 5000),
    metadata: { page_url: cleanUrl(payload.page_url) },
  });
  if (activityError) return response(503, { error: "activity_create_failed" }, origin);

  return response(existingContact ? 200 : 201, { accepted: true, contact_id: contactId, visitor_linked: Boolean(visitorId) }, origin);
});
