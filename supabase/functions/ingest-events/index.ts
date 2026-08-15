import "jsr:@supabase/functions-js/edge-runtime.d.ts";
import { createClient } from "npm:@supabase/supabase-js@2.57.4";

const allowedEventTypes = new Set([
  "page_view",
  "click",
  "scroll",
  "heartbeat",
  "form_started",
  "form_submitted",
  "download",
  "voice_intent",
]);

const jsonHeaders = { "content-type": "application/json; charset=utf-8" };

function response(status: number, body: Record<string, unknown>, origin = "") {
  const headers: Record<string, string> = { ...jsonHeaders, "cache-control": "no-store" };
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

function scoreFor(type: string, pageUrl: string | null) {
  const scores: Record<string, number> = { page_view: 2, click: 3, scroll: 1, heartbeat: 0, form_started: 15, form_submitted: 40, download: 8, voice_intent: 15 };
  return Math.min(100, (scores[type] ?? 0) + (type === "page_view" && /pricing|quote|consult|contact/i.test(pageUrl ?? "") ? 10 : 0));
}

async function sha256(value: string) {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

Deno.serve(async (request: Request) => {
  const origin = request.headers.get("origin") ?? "";
  if (request.method === "OPTIONS") {
    return new Response(null, {
      status: 204,
      headers: {
        "access-control-allow-origin": origin,
        "access-control-allow-methods": "POST, OPTIONS",
        "access-control-allow-headers": "content-type, x-neocrm-site, x-neocrm-token",
        "access-control-max-age": "600",
        "vary": "Origin",
      },
    });
  }
  if (request.method !== "POST") return response(405, { error: "method_not_allowed" }, origin);

  const sitePublicId = request.headers.get("x-neocrm-site") ?? "";
  const siteToken = request.headers.get("x-neocrm-token") ?? "";
  if (!validUuid(sitePublicId) || siteToken.length < 32 || siteToken.length > 256) {
    return response(401, { error: "invalid_site_credentials" }, origin);
  }

  let payload: Record<string, unknown>;
  try {
    payload = await request.json();
  } catch {
    return response(400, { error: "invalid_json" }, origin);
  }

  const eventType = cleanText(payload.event_type, 40);
  if (!allowedEventTypes.has(eventType) || !validUuid(payload.visitor_uuid) || !validUuid(payload.session_uuid)) {
    return response(400, { error: "invalid_event" }, origin);
  }
  const requestId = validUuid(payload.request_id) ? payload.request_id : crypto.randomUUID();

  const supabaseUrl = Deno.env.get("SUPABASE_URL");
  const serviceRoleKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");
  if (!supabaseUrl || !serviceRoleKey) return response(503, { error: "service_unavailable" }, origin);

  const db = createClient(supabaseUrl, serviceRoleKey, { auth: { persistSession: false, autoRefreshToken: false } });
  const tokenHash = await sha256(siteToken);
  const { data: verified, error: verifyError } = await db.rpc("verify_site_token", {
    p_site_public_id: sitePublicId,
    p_token_hash: tokenHash,
  });
  if (verifyError || !verified?.length) return response(401, { error: "invalid_site_credentials" }, origin);

  const site = verified[0];
  const normalizedOrigin = origin.replace(/\/$/, "");
  const allowedOrigins = Array.isArray(site.allowed_origins) ? site.allowed_origins.map((item: string) => item.replace(/\/$/, "")) : [];
  if (!normalizedOrigin || !allowedOrigins.includes(normalizedOrigin)) {
    return response(403, { error: "origin_not_allowed" }, origin);
  }

  const rateWindow = new Date(Date.now() - 60_000).toISOString();
  const { count: recentEvents, error: rateError } = await db.from("events").select("id", { count: "exact", head: true }).eq("site_id", site.site_id).gte("occurred_at", rateWindow);
  if (rateError) return response(503, { error: "rate_check_failed" }, origin);
  if ((recentEvents ?? 0) >= 600) return response(429, { error: "site_rate_limit" }, origin);

  const pageUrl = cleanUrl(payload.page_url);
  const referrer = cleanUrl(payload.referrer);
  const now = new Date().toISOString();

  const { data: existingEvent } = await db.from("events").select("id").eq("site_id", site.site_id).eq("request_id", requestId).maybeSingle();
  if (existingEvent) return response(202, { accepted: true, duplicate: true, request_id: requestId }, origin);

  const { data: existingVisitor, error: visitorReadError } = await db.from("visitors").select("id,sessions_count,pageviews_count,engagement_score").eq("site_id", site.site_id).eq("visitor_uuid", payload.visitor_uuid).maybeSingle();
  if (visitorReadError) return response(503, { error: "visitor_lookup_failed" }, origin);

  let visitor = existingVisitor;
  if (!visitor) {
    const { data, error } = await db.from("visitors").insert({
      tenant_id: site.tenant_id,
      site_id: site.site_id,
      visitor_uuid: payload.visitor_uuid,
      first_landing_url: pageUrl,
      first_referrer: referrer,
      locale: cleanText(payload.locale, 20),
      device_type: cleanText(payload.device_type, 20),
    }).select("id,sessions_count,pageviews_count,engagement_score").single();
    if (error) return response(503, { error: "visitor_create_failed" }, origin);
    visitor = data;
  }

  const { error: journeyError } = await db.from("customer_journeys").upsert({
    tenant_id: site.tenant_id,
    site_id: site.site_id,
    visitor_id: visitor.id,
    stage: "visitor",
    source: referrer ? "referral" : "direct",
    entry_method: "website",
  }, { onConflict: "tenant_id,visitor_id", ignoreDuplicates: true });
  if (journeyError) return response(503, { error: "journey_create_failed" }, origin);

  const { data: existingSession, error: sessionReadError } = await db.from("sessions").select("id").eq("site_id", site.site_id).eq("session_uuid", payload.session_uuid).maybeSingle();
  if (sessionReadError) return response(503, { error: "session_lookup_failed" }, origin);

  let session = existingSession;
  let newSession = false;
  if (!session) {
    const { data, error } = await db.from("sessions").insert({
      tenant_id: site.tenant_id,
      site_id: site.site_id,
      visitor_id: visitor.id,
      session_uuid: payload.session_uuid,
      landing_url: pageUrl,
      referrer,
      utm_source: cleanText(payload.utm_source, 190),
      utm_medium: cleanText(payload.utm_medium, 190),
      utm_campaign: cleanText(payload.utm_campaign, 190),
    }).select("id").single();
    if (error) return response(503, { error: "session_create_failed" }, origin);
    session = data;
    newSession = true;
  } else {
    await db.from("sessions").update({ last_activity_at: now }).eq("id", session.id);
  }

  const allowedMetadata = ["href", "depth", "seconds", "intent_category", "language"];
  const rawMetadata = payload.event_data && typeof payload.event_data === "object" ? payload.event_data as Record<string, unknown> : {};
	const eventData = Object.fromEntries(allowedMetadata.filter((key) => key in rawMetadata).map((key) => [key, key === "href" ? cleanUrl(rawMetadata[key]) : cleanText(rawMetadata[key], 255)]));
  const { error: eventError } = await db.from("events").insert({
    tenant_id: site.tenant_id,
    site_id: site.site_id,
    visitor_id: visitor.id,
    session_id: session.id,
    request_id: requestId,
    event_type: eventType,
    page_url: pageUrl,
    page_title: cleanText(payload.page_title, 255),
    element_name: cleanText(payload.element_name, 190),
    event_data: eventData,
  });
  if (eventError) return response(503, { error: "event_create_failed" }, origin);

  await db.from("visitors").update({
    last_seen: now,
    locale: cleanText(payload.locale, 20),
    device_type: cleanText(payload.device_type, 20),
    sessions_count: Number(visitor.sessions_count) + (newSession ? 1 : 0),
    pageviews_count: Number(visitor.pageviews_count) + (eventType === "page_view" ? 1 : 0),
    engagement_score: Math.min(100, Number(visitor.engagement_score) + scoreFor(eventType, pageUrl)),
  }).eq("id", visitor.id);

  return response(202, { accepted: true, request_id: requestId }, origin);
});
