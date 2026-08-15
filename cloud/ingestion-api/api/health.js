import { sourceRegistryStatus } from '../lib/source-registry.js';

export default function handler(_request, response) {
  response.setHeader('Cache-Control', 'no-store');
  return response.status(200).json({
    service: 'neocrm-ingestion',
    status: 'ok',
    version: '0.1.0',
    sourceRegistry: sourceRegistryStatus(),
  });
}

