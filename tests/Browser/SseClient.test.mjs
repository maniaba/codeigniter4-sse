import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    SseClient,
    SseClientStatus,
} from '../../resources/js/sse-client.js';

class FakeEventSource {
    constructor() {
        this.readyState = 0;
        this.closed = false;
        this.listeners = new Map();
    }

    addEventListener(name, handler) {
        const handlers = this.listeners.get(name) ?? new Set();
        handlers.add(handler);
        this.listeners.set(name, handlers);
    }

    removeEventListener(name, handler) {
        this.listeners.get(name)?.delete(handler);
    }

    close() {
        this.closed = true;
        this.readyState = 2;
    }

    dispatch(name, event = {}) {
        for (const handler of this.listeners.get(name) ?? []) {
            handler(event);
        }
    }
}

test('builds the URL, dispatches envelopes, reports status, and closes', () => {
    const source = new FakeEventSource();
    const statuses = [];
    const named = [];
    const globalMessages = [];
    let receivedUrl;
    let receivedOptions;

    const client = new SseClient({
        endpoint: 'https://example.test/sse?locale=bs',
        channels: ['users.42', 'orders.918', 'users.42'],
        query: { tenant: 7 },
        eventSourceFactory: (url, options) => {
            receivedUrl = url;
            receivedOptions = options;

            return source;
        },
    });

    client
        .on('status', ({ status }) => statuses.push(status))
        .on('order.updated', (message) => named.push(message))
        .onMessage((message) => globalMessages.push(message))
        .connect();

    const url = new URL(receivedUrl);
    assert.equal(url.searchParams.get('channels'), 'users.42,orders.918');
    assert.equal(url.searchParams.get('tenant'), '7');
    assert.equal(url.searchParams.get('locale'), 'bs');
    assert.deepEqual(receivedOptions, { withCredentials: true });
    assert.deepEqual(statuses, [SseClientStatus.CONNECTING]);

    source.readyState = 1;
    source.dispatch('open', { type: 'open' });
    source.dispatch('order.updated', {
        data: JSON.stringify({
            id: 'event-1',
            event: 'order.updated',
            channel: 'orders.918',
            data: { status: 'paid' },
            occurredAt: '2026-07-30T19:10:00+00:00',
            version: 1,
        }),
        lastEventId: 'event-1',
    });

    assert.equal(client.status, SseClientStatus.OPEN);
    assert.equal(named.length, 1);
    assert.equal(globalMessages.length, 1);
    assert.deepEqual(named[0].data, { status: 'paid' });
    assert.equal(named[0].channel, 'orders.918');
    assert.equal(named[0].version, 1);

    source.readyState = 0;
    source.dispatch('error', { type: 'error' });
    assert.equal(client.status, SseClientStatus.RECONNECTING);

    client.close();
    assert.equal(source.closed, true);
    assert.equal(client.status, SseClientStatus.CLOSED);
    assert.deepEqual(statuses, [
        SseClientStatus.CONNECTING,
        SseClientStatus.OPEN,
        SseClientStatus.RECONNECTING,
        SseClientStatus.CLOSED,
    ]);
});

test('preserves invalid JSON and invokes unsupported fallback once', () => {
    const source = new FakeEventSource();
    const messages = [];
    const client = new SseClient({
        endpoint: 'https://example.test/sse',
        eventSourceFactory: () => source,
    });

    client.onMessage((message) => messages.push(message)).connect();
    source.dispatch('message', { data: 'not-json', lastEventId: '' });

    assert.equal(messages[0].parsed, false);
    assert.equal(messages[0].data, 'not-json');
    assert.ok(messages[0].parseError instanceof Error);

    let fallbackCalls = 0;
    const unsupported = new SseClient({
        endpoint: 'https://example.test/sse',
        fallback: ({ reason }) => {
            assert.equal(reason, 'unsupported');
            fallbackCalls++;
        },
    });

    unsupported.connect();
    assert.equal(unsupported.status, SseClientStatus.UNSUPPORTED);
    assert.equal(fallbackCalls, 1);
});

test('subscribes and unsubscribes channels by reconnecting active sources', () => {
    const sources = [];
    const urls = [];
    const statuses = [];

    const client = new SseClient({
        endpoint: 'https://example.test/sse',
        channels: ['public.news'],
        eventSourceFactory: (url) => {
            const source = new FakeEventSource();

            sources.push(source);
            urls.push(url);

            return source;
        },
    });

    client.on('status', ({ status }) => statuses.push(status)).connect();
    sources[0].readyState = 1;
    sources[0].dispatch('open', { type: 'open' });

    client.subscribe(['users.42', 'public.news']);

    assert.equal(sources.length, 2);
    assert.equal(sources[0].closed, true);
    assert.equal(
        new URL(urls[1]).searchParams.get('channels'),
        'public.news,users.42',
    );
    assert.equal(client.status, SseClientStatus.CONNECTING);

    sources[1].readyState = 1;
    sources[1].dispatch('open', { type: 'open' });

    client.unsubscribe('public.news');

    assert.equal(sources.length, 3);
    assert.equal(sources[1].closed, true);
    assert.equal(new URL(urls[2]).searchParams.get('channels'), 'users.42');

    client.unsubscribe('users.42');

    assert.equal(sources.length, 3);
    assert.equal(sources[2].closed, true);
    assert.equal(client.status, SseClientStatus.CLOSED);
    assert.deepEqual(client.channels, []);
    assert.deepEqual(statuses, [
        SseClientStatus.CONNECTING,
        SseClientStatus.OPEN,
        SseClientStatus.CONNECTING,
        SseClientStatus.OPEN,
        SseClientStatus.CONNECTING,
        SseClientStatus.CLOSED,
    ]);
});
