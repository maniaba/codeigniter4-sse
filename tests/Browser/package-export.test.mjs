import assert from 'node:assert/strict';
import { test } from 'node:test';

import SseClientDefault, {
    SseClient,
    SseClientStatus,
} from '@maniaba/codeigniter4-sse-browser';

test('exports the browser client as an npm package entrypoint', () => {
    assert.equal(SseClientDefault, SseClient);
    assert.equal(SseClientStatus.IDLE, 'idle');
});
