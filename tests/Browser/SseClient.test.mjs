import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    DirectSseAdapter,
    InMemorySseAdapter,
    MercureSseAdapter,
    RedisSseAdapter,
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

const nextTurn = () => new Promise((resolve) => setImmediate(resolve));
const wait = (milliseconds) => new Promise((resolve) => {
    setTimeout(resolve, milliseconds);
});

test('uses the direct adapter by default', () => {
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

    assert.equal(client.adapter instanceof DirectSseAdapter, true);
    assert.equal(Object.hasOwn(client, 'transport'), false);
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

test('provides semantic direct adapters for built-in PHP stream brokers', () => {
    const redis = new RedisSseAdapter();
    const memory = new InMemorySseAdapter();

    assert.deepEqual(
        redis.resolve({ url: 'https://example.test/sse' }),
        { url: 'https://example.test/sse', expiresAt: null },
    );
    assert.deepEqual(
        memory.resolve({ url: 'https://example.test/sse' }),
        { url: 'https://example.test/sse', expiresAt: null },
    );
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
        adapter: new RedisSseAdapter(),
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

test('resolves Mercure authorization and opens EventSource directly on the Hub', async () => {
    const source = new FakeEventSource();
    const fetchCalls = [];
    let receivedHubUrl;
    let receivedOptions;

    const client = new SseClient({
        endpoint: 'https://app.example.test/sse',
        adapter: new MercureSseAdapter({
            fetchFactory: async (url, options) => {
                fetchCalls.push({ url, options });

                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        hub: 'https://hub.example.test/.well-known/mercure?custom=1',
                        topics: [
                            'urn:example:sse:users.42',
                            'urn:example:sse:projects.7',
                        ],
                        expiresAt: null,
                    }),
                };
            },
        }),
        channels: ['users.42', 'projects.7'],
        eventSourceFactory: (url, options) => {
            receivedHubUrl = url;
            receivedOptions = options;

            return source;
        },
    });

    client.connect();
    client.connect();
    await nextTurn();

    assert.equal(fetchCalls.length, 1);
    assert.equal(
        new URL(fetchCalls[0].url).searchParams.get('channels'),
        'users.42,projects.7',
    );
    assert.equal(fetchCalls[0].options.credentials, 'include');
    assert.equal(fetchCalls[0].options.headers.Accept, 'application/json');

    const hubUrl = new URL(receivedHubUrl);
    assert.equal(hubUrl.searchParams.get('custom'), '1');
    assert.deepEqual(
        hubUrl.searchParams.getAll('topic'),
        [
            'urn:example:sse:users.42',
            'urn:example:sse:projects.7',
        ],
    );
    assert.deepEqual(receivedOptions, { withCredentials: true });

    source.readyState = 1;
    source.dispatch('open', { type: 'open' });
    assert.equal(client.status, SseClientStatus.OPEN);

    client.close();
});

test('aborts stale Mercure authorization when channels change', async () => {
    const authorizationUrls = [];
    const signals = [];
    const sourceUrls = [];
    const adapter = new MercureSseAdapter({
        fetchFactory: async (url, { signal }) => {
            authorizationUrls.push(url);
            signals.push(signal);

            if (authorizationUrls.length === 1) {
                return new Promise((resolve, reject) => {
                    signal.addEventListener('abort', () => {
                        reject(new Error('aborted'));
                    }, { once: true });
                });
            }

            return {
                ok: true,
                status: 200,
                json: async () => ({
                    hub: 'https://hub.example.test/.well-known/mercure',
                    topics: ['urn:example:sse:public.news', 'urn:example:sse:users.42'],
                    expiresAt: null,
                }),
            };
        },
    });

    const client = new SseClient({
        endpoint: 'https://example.test/sse',
        channels: ['public.news'],
        adapter,
        eventSourceFactory: (url) => {
            sourceUrls.push(url);

            return new FakeEventSource();
        },
    });

    client.connect();
    client.subscribe('users.42');
    await nextTurn();

    assert.equal(authorizationUrls.length, 2);
    assert.equal(signals[0].aborted, true);
    assert.equal(sourceUrls.length, 1);
    assert.deepEqual(
        new URL(sourceUrls[0]).searchParams.getAll('topic'),
        ['urn:example:sse:public.news', 'urn:example:sse:users.42'],
    );

    client.close();
});

test('reports adapter failures without opening EventSource', async () => {
    let eventSourceCalls = 0;
    const fallbackReasons = [];
    const client = new SseClient({
        endpoint: 'https://app.example.test/sse',
        channels: ['users.42'],
        adapter: new MercureSseAdapter({
            fetchFactory: async () => ({
                ok: false,
                status: 403,
                json: async () => ({}),
            }),
        }),
        eventSourceFactory: () => {
            eventSourceCalls++;

            return new FakeEventSource();
        },
        fallback: ({ reason }) => fallbackReasons.push(reason),
    });

    client.connect();
    await nextTurn();

    assert.equal(eventSourceCalls, 0);
    assert.equal(client.status, SseClientStatus.CLOSED);
    assert.deepEqual(fallbackReasons, ['adapter-error']);
});

test('closes an expiring adapter connection before refreshing it', async () => {
    let authorizationCalls = 0;
    const sources = [];
    const client = new SseClient({
        endpoint: 'https://example.test/sse',
        channels: ['public.news'],
        adapter: new MercureSseAdapter({
            fetchFactory: async () => {
                authorizationCalls++;

                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        hub: 'https://hub.example.test/.well-known/mercure',
                        topics: ['urn:example:sse:public.news'],
                        expiresAt: Math.floor(Date.now() / 1000) + 30,
                    }),
                };
            },
        }),
        eventSourceFactory: () => {
            const source = new FakeEventSource();
            sources.push(source);

            return source;
        },
    });

    client.connect();
    await nextTurn();
    await wait(1100);
    await nextTurn();

    assert.equal(authorizationCalls, 2);
    assert.equal(sources.length, 2);
    assert.equal(sources[0].closed, true);
    assert.equal(client.status, SseClientStatus.RECONNECTING);

    client.close();
});

test('keeps EventSource construction errors distinct from adapter errors', async () => {
    const fallbackReasons = [];
    const client = new SseClient({
        endpoint: 'https://app.example.test/sse',
        channels: ['users.42'],
        adapter: new MercureSseAdapter({
            fetchFactory: async () => ({
                ok: true,
                status: 200,
                json: async () => ({
                    hub: 'https://hub.example.test/.well-known/mercure',
                    topics: ['urn:example:sse:users.42'],
                    expiresAt: null,
                }),
            }),
        }),
        eventSourceFactory: () => {
            throw new Error('EventSource construction failed.');
        },
        fallback: ({ reason }) => fallbackReasons.push(reason),
    });

    client.connect();
    await nextTurn();

    assert.equal(client.status, SseClientStatus.CLOSED);
    assert.deepEqual(fallbackReasons, ['construction-error']);
});
