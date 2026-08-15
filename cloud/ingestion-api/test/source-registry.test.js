import test from 'node:test';
import assert from 'node:assert/strict';
import { querySourceRegistry, sourceRegistryStatus } from '../lib/source-registry.js';

const env = {
  NEO_SOURCE_REGISTRY_URL: 'https://neoos-source-registry.vercel.app',
  NEO_SOURCE_REGISTRY_CONSUMER: 'neocrm',
  NEO_SOURCE_REGISTRY_TOKEN: 'test_neocrm_token_12345678901234567890',
};

test('reports configured only for an HTTPS neocrm consumer', () => {
  assert.deepEqual(sourceRegistryStatus(env), { configured: true, consumer: 'neocrm' });
  assert.equal(sourceRegistryStatus({ ...env, NEO_SOURCE_REGISTRY_URL: 'http://example.com' }).configured, false);
  assert.equal(sourceRegistryStatus({ ...env, NEO_SOURCE_REGISTRY_CONSUMER: 'neocontent' }).configured, false);
});

test('fails open when registry is not configured', async () => {
  const result = await querySourceRegistry('dns-resolution', { name: 'example.com', type: 'A' }, { env: {} });
  assert.deepEqual(result, { ok: false, skipped: true, reason: 'not_configured' });
});

test('rejects private CRM fields before any external request', async () => {
  let called = false;
  await assert.rejects(
    querySourceRegistry('company-registry', { query: 'Example Ltd', email: 'person@example.com' }, {
      env,
      fetchImpl: async () => {
        called = true;
        throw new Error('must not run');
      },
    }),
    /not approved/,
  );
  assert.equal(called, false);
});

test('sends only bounded approved public fields and authenticates as neocrm', async () => {
  let request;
  const result = await querySourceRegistry('company-registry', { query: 'Example Ltd', limit: 3 }, {
    env,
    fetchImpl: async (url, options) => {
      request = { url, options };
      return {
        ok: true,
        json: async () => ({
          schemaVersion: 'neo-data-gateway-response-v1',
          consumer: 'neocrm',
          capability: 'company-registry',
          ok: true,
          provider: 'companies-house',
          data: [],
        }),
      };
    },
  });
  assert.equal(result.ok, true);
  assert.equal(request.url, 'https://neoos-source-registry.vercel.app/v1/query');
  assert.equal(request.options.headers['x-neo-consumer'], 'neocrm');
  assert.match(request.options.headers.authorization, /^Bearer /);
  assert.deepEqual(JSON.parse(request.options.body), {
    capability: 'company-registry',
    input: { query: 'Example Ltd', limit: 3 },
  });
});

test('does not expose gateway errors to callers', async () => {
  const result = await querySourceRegistry('dns-resolution', { name: 'example.com' }, {
    env,
    fetchImpl: async () => ({ ok: false, json: async () => ({ error: 'sensitive upstream detail' }) }),
  });
  assert.deepEqual(result, { ok: false, skipped: true, reason: 'gateway_unavailable' });
});
