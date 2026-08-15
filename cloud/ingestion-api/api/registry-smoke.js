import { querySourceRegistry } from '../lib/source-registry.js';

export default async function handler(request, response) {
  response.setHeader('Cache-Control', 'no-store');
  response.setHeader('X-Robots-Tag', 'noindex');
  if (request.method !== 'GET') return response.status(405).json({ error: 'Method not allowed' });

  const result = await querySourceRegistry('dns-resolution', { name: 'example.com', type: 'A' });
  return response.status(result.ok ? 200 : 503).json({
    ok: result.ok === true,
    consumer: result.consumer ?? null,
    capability: result.capability ?? null,
    provider: result.provider ?? null,
    schemaVersion: result.schemaVersion ?? null,
    skipped: result.skipped === true,
    reason: result.reason ?? null,
  });
}
