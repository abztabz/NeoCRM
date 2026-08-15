const DEFAULT_TIMEOUT_MS = 8000;
const ALLOWED_CAPABILITIES = new Set(['dns-resolution', 'company-registry']);
const ALLOWED_INPUT_KEYS = Object.freeze({
  'dns-resolution': new Set(['name', 'type']),
  'company-registry': new Set(['query', 'limit']),
});

function boundedString(value, maxLength) {
  return typeof value === 'string' ? value.trim().slice(0, maxLength) : '';
}

function registryConfig(env = process.env) {
  const url = boundedString(env.NEO_SOURCE_REGISTRY_URL, 500).replace(/\/$/, '');
  const consumer = boundedString(env.NEO_SOURCE_REGISTRY_CONSUMER, 64).toLowerCase();
  const token = boundedString(env.NEO_SOURCE_REGISTRY_TOKEN, 500);
  const validUrl = (() => {
    try {
      const parsed = new URL(url);
      return parsed.protocol === 'https:' ? parsed.toString().replace(/\/$/, '') : '';
    } catch {
      return '';
    }
  })();
  return {
    configured: Boolean(validUrl && consumer === 'neocrm' && token.length >= 24),
    url: validUrl,
    consumer,
    token,
  };
}

function sanitizeInput(capability, input) {
  if (!ALLOWED_CAPABILITIES.has(capability)) throw new Error('Capability is not approved for NeoCRM');
  if (!input || typeof input !== 'object' || Array.isArray(input)) throw new Error('Registry input must be an object');
  const allowed = ALLOWED_INPUT_KEYS[capability];
  const output = {};
  for (const [key, value] of Object.entries(input)) {
    if (!allowed.has(key)) throw new Error('Input field is not approved for NeoCRM registry use');
    if (key === 'limit') {
      const limit = Number(value);
      if (!Number.isFinite(limit) || limit < 1 || limit > 10) throw new Error('Invalid registry result limit');
      output.limit = Math.floor(limit);
      continue;
    }
    const text = boundedString(value, key === 'query' ? 200 : 253);
    if (!text) throw new Error('Registry input value is required');
    output[key] = text;
  }
  if (capability === 'company-registry' && !output.query) throw new Error('Company query is required');
  if (capability === 'dns-resolution' && !output.name) throw new Error('DNS name is required');
  return output;
}

function validGatewayPayload(payload, capability) {
  return Boolean(
    payload &&
    typeof payload === 'object' &&
    payload.schemaVersion === 'neo-data-gateway-response-v1' &&
    payload.consumer === 'neocrm' &&
    payload.capability === capability &&
    typeof payload.ok === 'boolean'
  );
}

export function sourceRegistryStatus(env = process.env) {
  const config = registryConfig(env);
  return { configured: config.configured, consumer: config.consumer === 'neocrm' ? 'neocrm' : null };
}

export async function querySourceRegistry(capability, input, options = {}) {
  const config = registryConfig(options.env ?? process.env);
  if (!config.configured) return { ok: false, skipped: true, reason: 'not_configured' };
  const safeInput = sanitizeInput(capability, input);
  const timeoutMs = Math.max(1000, Math.min(10000, Number(options.timeoutMs) || DEFAULT_TIMEOUT_MS));
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const fetchImpl = options.fetchImpl ?? fetch;
    const response = await fetchImpl(`${config.url}/v1/query`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-neo-consumer': 'neocrm',
        authorization: `Bearer ${config.token}`,
      },
      body: JSON.stringify({ capability, input: safeInput }),
      signal: controller.signal,
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !validGatewayPayload(payload, capability)) {
      return { ok: false, skipped: true, reason: 'gateway_unavailable' };
    }
    return payload;
  } catch {
    return { ok: false, skipped: true, reason: 'gateway_unavailable' };
  } finally {
    clearTimeout(timer);
  }
}
