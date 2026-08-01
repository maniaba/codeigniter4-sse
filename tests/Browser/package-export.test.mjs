import assert from 'node:assert/strict';
import { test } from 'node:test';

import SseClientDefault, {
    MercureSseAdapter,
    RedisSseAdapter,
    SseClient,
    SseClientStatus,
} from '@maniaba/codeigniter4-sse-browser';
import MercureSseAdapterDefault from '@maniaba/codeigniter4-sse-browser/adapters/mercure-sse-adapter.js';
import RedisSseAdapterDefault from '@maniaba/codeigniter4-sse-browser/adapters/redis-sse-adapter.js';

test('exports the browser client as an npm package entrypoint', () => {
    assert.equal(SseClientDefault, SseClient);
    assert.equal(SseClientStatus.IDLE, 'idle');
    assert.equal(RedisSseAdapterDefault, RedisSseAdapter);
    assert.equal(MercureSseAdapterDefault, MercureSseAdapter);
});
