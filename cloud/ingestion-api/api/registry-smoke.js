import { querySourceRegistry, sourceRegistryStatus } from '../lib/source-registry.js';

function safeHost(value) {
  try {
    const url = new URL(String(value || ''));
    return url.protocol === 'https:' ? url.host : null;
  } catch {
    return null;
  }
}

export default async function handler(request, response) {
  response.setHeader('Cache-Control', 'no-store');
  response.setHeader('X-Robots-Tag', 'noindex');
  if (request.method !== 'GET') return response.status(405).json({ error: 'Method not allowed' });

  const status = sourceRegistryStatus();
  const baseUrl = String(process.env.NEO_SOURCE_REGISTRY_URL || '').replace(/\/$/, '');
  let healthStatus = null;
  try {
    const healthResponse = await fetch(`${baseUrl}/healthz`, { signal: AbortSignal.timeout(5000), cache: 'no-store' });
    healthStatus = healthResponse.status;
  } catch {
    healthStatus = 0;
  }

  const result = await querySourceRegistry('dns-resolution', { name: 'example.com', type: 'A' });
  return response.status(result.ok ? 200 : 503).json({
    configured: status.configured === true,
    configuredConsumer: status.consumer,
    registryHost: safeHost(process.env.NEO_SOURCE_REGISTRY_URL),
    healthStatus,
    ok: result.ok === true,
    consumer: result.consumer ?? null,
    capability: result.capability ?? null,
    provider: result.provider ?? null,
    schemaVersion: result.schemaVersion ?? null,
    skipped: result.skipped === true,
    reason: result.reason ?? null,
  });
}
