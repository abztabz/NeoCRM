const UPSTREAM = 'https://myxywrsumtcnafeidshp.supabase.co/functions/v1/ingest-events';
const MAX_BODY_BYTES = 16 * 1024;

export default async function handler(request, response) {
  response.setHeader('Cache-Control', 'no-store');
  if (request.method !== 'POST') return response.status(405).json({ error: 'method_not_allowed' });

  const site = String(request.headers['x-neocrm-site'] || '');
  const token = String(request.headers['x-neocrm-token'] || '');
  const origin = String(request.headers.origin || '');
  const serialized = JSON.stringify(request.body || {});
  if (!site || !token || !origin) return response.status(401).json({ error: 'missing_site_credentials' });
  if (Buffer.byteLength(serialized, 'utf8') > MAX_BODY_BYTES) return response.status(413).json({ error: 'payload_too_large' });

  try {
    const upstream = await fetch(UPSTREAM, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'origin': origin,
        'x-neocrm-site': site,
        'x-neocrm-token': token,
        'x-forwarded-for': String(request.headers['x-forwarded-for'] || '').split(',')[0].trim(),
      },
      body: serialized,
      signal: AbortSignal.timeout(8000),
    });
    const body = await upstream.text();
    response.status(upstream.status);
    response.setHeader('Content-Type', upstream.headers.get('content-type') || 'application/json');
    return response.send(body);
  } catch {
    return response.status(503).json({ error: 'upstream_unavailable' });
  }
}

