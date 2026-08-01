/**
 * Small EventSource wrapper for maniaba/codeigniter4-sse.
 *
 * Broker-specific connection resolution lives in adapters. The client owns
 * lifecycle, message normalization, channel changes, and EventSource handlers.
 */

import { DirectSseAdapter } from './adapters/direct-sse-adapter.js';

export { DirectSseAdapter } from './adapters/direct-sse-adapter.js';
export { InMemorySseAdapter } from './adapters/in-memory-sse-adapter.js';
export { LocalSseAdapter } from './adapters/local-sse-adapter.js';
export { MercureSseAdapter } from './adapters/mercure-sse-adapter.js';
export { RedisSseAdapter } from './adapters/redis-sse-adapter.js';

export const SseClientStatus = Object.freeze({
    IDLE: 'idle',
    CONNECTING: 'connecting',
    OPEN: 'open',
    RECONNECTING: 'reconnecting',
    CLOSED: 'closed',
    UNSUPPORTED: 'unsupported',
});

const GLOBAL_MESSAGE_EVENT = 'message';
const STATUS_EVENT = 'status';
const RESERVED_NATIVE_EVENTS = new Set(['open', 'error']);

/**
 * @typedef {Object} SseMessage
 * @property {string|null} id
 * @property {string} event
 * @property {string|null} channel
 * @property {*} data
 * @property {string|null} occurredAt
 * @property {number|null} version
 * @property {string} raw
 * @property {boolean} parsed
 * @property {Error|null} parseError
 * @property {MessageEvent} originalEvent
 */

/**
 * @typedef {Object} SseClientOptions
 * @property {string} endpoint
 * @property {Object|null} [adapter]
 * @property {string[]} [channels]
 * @property {Object|URLSearchParams} [query]
 * @property {boolean} [withCredentials]
 * @property {Function|null} [fallback]
 * @property {Function|null} [eventSourceFactory]
 */

export class SseClient {
    /**
     * @param {SseClientOptions} options
     */
    constructor({
        endpoint,
        adapter = new DirectSseAdapter(),
        channels = [],
        query = {},
        withCredentials = true,
        fallback = null,
        eventSourceFactory = null,
    } = {}) {
        const queryIsUrlSearchParams = (
            typeof globalThis !== 'undefined'
            && typeof globalThis.URLSearchParams === 'function'
            && query instanceof globalThis.URLSearchParams
        );

        if (typeof endpoint !== 'string' || endpoint.trim() === '') {
            throw new TypeError('SseClient requires a non-empty endpoint.');
        }

        if (
            !queryIsUrlSearchParams
            && (query === null || typeof query !== 'object' || Array.isArray(query))
        ) {
            throw new TypeError(
                'SseClient query must be an object or URLSearchParams.',
            );
        }

        if (
            adapter === null
            || typeof adapter !== 'object'
            || typeof adapter.resolve !== 'function'
        ) {
            throw new TypeError('SseClient adapter must provide a resolve() method.');
        }

        if (fallback !== null && typeof fallback !== 'function') {
            throw new TypeError('SseClient fallback must be a function or null.');
        }

        if (
            eventSourceFactory !== null
            && typeof eventSourceFactory !== 'function'
        ) {
            throw new TypeError(
                'SseClient eventSourceFactory must be a function or null.',
            );
        }

        this.endpoint = endpoint.trim();
        this.adapter = adapter;
        this.channels = this._normalizeChannels(channels);
        this.query = query;
        this.withCredentials = Boolean(withCredentials);

        this._queryIsUrlSearchParams = queryIsUrlSearchParams;
        this._fallback = fallback;
        this._eventSourceFactory = eventSourceFactory;
        this._listeners = new Map();
        this._nativeMessageHandlers = new Map();
        this._source = null;
        this._status = SseClientStatus.IDLE;
        this._currentUrl = null;
        this._manuallyClosed = false;
        this._fallbackInvoked = false;
        this._connectionGeneration = 0;
        this._refreshTimer = null;

        this._handleOpen = this._handleOpen.bind(this);
        this._handleError = this._handleError.bind(this);
        this._handleDefaultMessage = this._handleDefaultMessage.bind(this);
    }

    /**
     * Current lifecycle state.
     *
     * @returns {string}
     */
    get status() {
        return this._status;
    }

    /**
     * URL used by the active or most recent connection.
     *
     * @returns {string|null}
     */
    get url() {
        return this._currentUrl;
    }

    /**
     * Register a named SSE event handler.
     *
     * `message` is the global handler and receives every event handled by this
     * wrapper. `status` receives lifecycle changes.
     *
     * @param {string} eventName
     * @param {Function} handler
     * @returns {SseClient}
     */
    on(eventName, handler) {
        this._assertEventName(eventName);

        if (typeof handler !== 'function') {
            throw new TypeError('SseClient event handler must be a function.');
        }

        let handlers = this._listeners.get(eventName);

        if (handlers === undefined) {
            handlers = new Set();
            this._listeners.set(eventName, handlers);
        }

        handlers.add(handler);

        if (this._isNamedMessageEvent(eventName)) {
            this._attachNamedMessageEvent(eventName);
        }

        return this;
    }

    /**
     * Remove one handler, or every handler for an event when omitted.
     *
     * @param {string} eventName
     * @param {Function} [handler]
     * @returns {SseClient}
     */
    off(eventName, handler) {
        this._assertEventName(eventName);

        const handlers = this._listeners.get(eventName);

        if (handlers === undefined) {
            return this;
        }

        if (handler === undefined) {
            handlers.clear();
        } else {
            handlers.delete(handler);
        }

        if (handlers.size === 0) {
            this._listeners.delete(eventName);

            if (this._isNamedMessageEvent(eventName)) {
                this._detachNamedMessageEvent(eventName);
            }
        }

        return this;
    }

    /**
     * Convenience alias for the global `message` handler.
     *
     * @param {Function} handler
     * @returns {SseClient}
     */
    onMessage(handler) {
        return this.on(GLOBAL_MESSAGE_EVENT, handler);
    }

    /**
     * Replace all subscribed channels. If the connection is active, it is
     * restarted with the new channel query.
     *
     * @param {string|string[]} channels
     * @returns {SseClient}
     */
    setChannels(channels) {
        const nextChannels = this._normalizeChannels(channels);

        if (this._sameChannels(nextChannels)) {
            return this;
        }

        this.channels = nextChannels;
        this._restartForChannelChange();

        return this;
    }

    /**
     * Add one or more channels. If the connection is active, it is restarted
     * with the expanded channel query.
     *
     * @param {string|string[]} channels
     * @returns {SseClient}
     */
    subscribe(channels) {
        const additions = this._normalizeChannels(channels);
        const next = new Set(this.channels);
        let changed = false;

        for (const channel of additions) {
            if (!next.has(channel)) {
                next.add(channel);
                changed = true;
            }
        }

        if (!changed) {
            return this;
        }

        this.channels = [...next];
        this._restartForChannelChange();

        return this;
    }

    /**
     * Remove one or more channels. If the connection is active, it is restarted
     * with the remaining channels. Removing the last channel closes the stream.
     *
     * @param {string|string[]} channels
     * @returns {SseClient}
     */
    unsubscribe(channels) {
        const removals = new Set(this._normalizeChannels(channels));
        const nextChannels = this.channels.filter((channel) => (
            !removals.has(channel)
        ));

        if (this._sameChannels(nextChannels)) {
            return this;
        }

        this.channels = nextChannels;
        this._restartForChannelChange();

        return this;
    }

    /**
     * Open the EventSource connection.
     *
     * Repeated calls while a source or adapter resolution is active are
     * idempotent.
     *
     * @returns {SseClient}
     */
    connect() {
        if (this._hasActiveSource()) {
            return this;
        }

        this._startConnection();

        return this;
    }

    /**
     * Close the active source. Registered handlers remain available for a
     * later connect() call.
     *
     * @returns {SseClient}
     */
    close() {
        this._manuallyClosed = true;
        this._connectionGeneration++;
        this._cancelAdapter();
        this._teardownSource();
        this._setStatus(SseClientStatus.CLOSED, { reason: 'manual' });

        return this;
    }

    _startConnection(preserveSource = false) {
        this._cancelAdapter();
        this._clearRefreshTimer();

        if (this._source !== null && !preserveSource) {
            this._teardownSource();
        }

        this._manuallyClosed = false;
        this._fallbackInvoked = false;
        const generation = ++this._connectionGeneration;

        const factory = this._resolveEventSourceFactory();

        if (factory === null) {
            try {
                this._currentUrl = this._buildUrl();
            } catch {
                this._currentUrl = this.endpoint;
            }

            this._setStatus(SseClientStatus.UNSUPPORTED, {
                reason: 'eventsource-unavailable',
            });

            const handled = this._invokeFallback('unsupported');

            if (!handled) {
                throw new Error(
                    'EventSource is not available and no fallback was provided.',
                );
            }

            return;
        }

        const endpointUrl = this._buildUrl();
        this._currentUrl = endpointUrl;
        this._setStatus(preserveSource
            ? SseClientStatus.RECONNECTING
            : SseClientStatus.CONNECTING);

        let resolution;

        try {
            resolution = this.adapter.resolve({
                url: endpointUrl,
                channels: [...this.channels],
                withCredentials: this.withCredentials,
                client: this,
            });
        } catch (error) {
            this._handleAdapterError(error, generation, preserveSource, true);

            return;
        }

        if (resolution !== null && typeof resolution?.then === 'function') {
            resolution
                .then((connection) => {
                    this._openAdapterConnection(
                        connection,
                        factory,
                        generation,
                        preserveSource,
                        false,
                    );
                })
                .catch((error) => {
                    this._handleAdapterError(
                        error,
                        generation,
                        preserveSource,
                        false,
                    );
                });

            return;
        }

        this._openAdapterConnection(
            resolution,
            factory,
            generation,
            preserveSource,
            true,
        );
    }

    /**
     * @param {*} connection
     * @param {Function} factory
     * @param {number} generation
     * @param {boolean} preserveSource
     * @param {boolean} throwOnUnhandled
     */
    _openAdapterConnection(
        connection,
        factory,
        generation,
        preserveSource,
        throwOnUnhandled,
    ) {
        if (
            generation !== this._connectionGeneration
            || this._manuallyClosed
        ) {
            return;
        }

        let normalized;

        try {
            normalized = this._normalizeConnection(connection);
        } catch (error) {
            this._handleAdapterError(
                error,
                generation,
                preserveSource,
                throwOnUnhandled,
            );

            return;
        }

        if (preserveSource && this._source !== null) {
            this._teardownSource();
        }

        this._currentUrl = normalized.url;
        const opened = this._openSource(
            factory,
            normalized.url,
            throwOnUnhandled,
        );

        if (opened) {
            this._scheduleRefresh(normalized.expiresAt, generation);
        }
    }

    /**
     * @param {*} connection
     * @returns {{url: string, expiresAt: number|null}}
     */
    _normalizeConnection(connection) {
        const url = connection?.url;
        const expiresAt = connection?.expiresAt ?? null;

        if (
            connection === null
            || typeof connection !== 'object'
            || typeof url !== 'string'
            || url.trim() === ''
            || (
                expiresAt !== null
                && (
                    !Number.isSafeInteger(expiresAt)
                    || expiresAt <= Math.floor(Date.now() / 1000)
                )
            )
        ) {
            throw new TypeError('The SSE adapter returned invalid connection data.');
        }

        let streamUrl;

        try {
            streamUrl = new URL(url);
        } catch (error) {
            throw new TypeError(
                'The SSE adapter returned an invalid stream URL.',
                { cause: error },
            );
        }

        if (streamUrl.protocol !== 'http:' && streamUrl.protocol !== 'https:') {
            throw new TypeError('The SSE stream URL must use HTTP or HTTPS.');
        }

        streamUrl.hash = '';

        return {
            url: streamUrl.toString(),
            expiresAt,
        };
    }

    /**
     * @param {Function} factory
     * @param {string} url
     * @param {boolean} [throwOnUnhandled]
     * @returns {boolean}
     */
    _openSource(factory, url, throwOnUnhandled = true) {
        let source;

        try {
            source = factory(url, {
                withCredentials: this.withCredentials,
            });
        } catch (error) {
            this._source = null;
            this._setStatus(SseClientStatus.CLOSED, {
                reason: 'construction-error',
                error,
            });

            const handled = this._invokeFallback(
                'construction-error',
                null,
                error,
            );

            if (!handled && throwOnUnhandled) {
                throw error;
            }

            if (!handled) {
                this._reportHandlerError(error);
            }

            return false;
        }

        if (
            source === null
            || typeof source !== 'object'
            || typeof source.addEventListener !== 'function'
            || typeof source.removeEventListener !== 'function'
            || typeof source.close !== 'function'
        ) {
            if (source !== null && typeof source?.close === 'function') {
                source.close();
            }

            const error = new TypeError(
                'The EventSource factory returned an invalid source.',
            );

            this._setStatus(SseClientStatus.CLOSED, {
                reason: 'invalid-eventsource',
                error,
            });

            const handled = this._invokeFallback(
                'construction-error',
                null,
                error,
            );

            if (!handled && throwOnUnhandled) {
                throw error;
            }

            if (!handled) {
                this._reportHandlerError(error);
            }

            return false;
        }

        this._source = source;
        this._source.addEventListener('open', this._handleOpen);
        this._source.addEventListener('error', this._handleError);
        this._source.addEventListener(
            GLOBAL_MESSAGE_EVENT,
            this._handleDefaultMessage,
        );

        for (const eventName of this._listeners.keys()) {
            if (this._isNamedMessageEvent(eventName)) {
                this._attachNamedMessageEvent(eventName);
            }
        }

        return true;
    }

    /**
     * @returns {Function|null}
     */
    _resolveEventSourceFactory() {
        if (this._eventSourceFactory !== null) {
            return this._eventSourceFactory;
        }

        if (
            typeof globalThis === 'undefined'
            || typeof globalThis.EventSource !== 'function'
        ) {
            return null;
        }

        return (url, options) => new globalThis.EventSource(url, options);
    }

    /**
     * @param {*} error
     * @param {number} generation
     * @param {boolean} preserveSource
     * @param {boolean} throwOnUnhandled
     */
    _handleAdapterError(error, generation, preserveSource, throwOnUnhandled) {
        if (
            generation !== this._connectionGeneration
            || this._manuallyClosed
        ) {
            return;
        }

        if (preserveSource && this._source !== null) {
            this._teardownSource();
        } else {
            this._source = null;
        }

        this._setStatus(SseClientStatus.CLOSED, {
            reason: 'adapter-error',
            error,
        });

        if (
            generation !== this._connectionGeneration
            || this._manuallyClosed
        ) {
            return;
        }

        const handled = this._invokeFallback('adapter-error', null, error);

        if (!handled && throwOnUnhandled) {
            throw error;
        }

        if (!handled) {
            this._reportHandlerError(error);
        }
    }

    /**
     * @param {number|null} expiresAt
     * @param {number} generation
     */
    _scheduleRefresh(expiresAt, generation) {
        this._clearRefreshTimer();

        if (expiresAt === null) {
            return;
        }

        const delay = Math.min(
            2_147_483_647,
            Math.max(1000, (expiresAt * 1000) - Date.now() - 30000),
        );

        this._refreshTimer = setTimeout(() => {
            if (
                generation !== this._connectionGeneration
                || this._manuallyClosed
            ) {
                return;
            }

            this._startConnection(true);
        }, delay);

        this._refreshTimer?.unref?.();
    }

    /**
     * @returns {boolean}
     */
    _hasActiveSource() {
        return (
            (
                this._source !== null
                || this._status === SseClientStatus.CONNECTING
            )
            && this._status !== SseClientStatus.CLOSED
            && this._status !== SseClientStatus.UNSUPPORTED
        );
    }

    _restartForChannelChange() {
        if (!this._hasActiveSource()) {
            return;
        }

        this._connectionGeneration++;
        this._cancelAdapter();
        this._teardownSource();

        if (this.channels.length === 0) {
            this._setStatus(SseClientStatus.CLOSED, {
                reason: 'channels-empty',
            });

            return;
        }

        this._startConnection();
    }

    /**
     * @returns {string}
     */
    _buildUrl() {
        const baseUrl = (
            typeof globalThis !== 'undefined'
            && globalThis.location !== undefined
            && typeof globalThis.location.href === 'string'
        )
            ? globalThis.location.href
            : undefined;

        let url;

        try {
            url = baseUrl === undefined
                ? new URL(this.endpoint)
                : new URL(this.endpoint, baseUrl);
        } catch (error) {
            throw new TypeError(
                'A relative SSE endpoint requires a browser location.',
                { cause: error },
            );
        }

        if (this._queryIsUrlSearchParams) {
            for (const [name, value] of this.query.entries()) {
                url.searchParams.append(name, value);
            }
        } else {
            for (const [name, value] of Object.entries(this.query)) {
                if (value === null || value === undefined) {
                    continue;
                }

                url.searchParams.delete(name);

                if (Array.isArray(value)) {
                    for (const item of value) {
                        url.searchParams.append(name, String(item));
                    }
                } else {
                    url.searchParams.set(name, String(value));
                }
            }
        }

        if (this.channels.length > 0) {
            url.searchParams.set('channels', this.channels.join(','));
        }

        url.hash = '';

        return url.toString();
    }

    /**
     * @param {string|string[]} channels
     * @returns {string[]}
     */
    _normalizeChannels(channels) {
        const values = Array.isArray(channels) ? channels : [channels];

        if (values.some((channel) => (
            typeof channel !== 'string' || channel.trim() === ''
        ))) {
            throw new TypeError('SseClient channels must be non-empty strings.');
        }

        return [...new Set(values.map((channel) => channel.trim()))];
    }

    /**
     * @param {string[]} channels
     * @returns {boolean}
     */
    _sameChannels(channels) {
        return (
            this.channels.length === channels.length
            && this.channels.every((channel, index) => channel === channels[index])
        );
    }

    /**
     * @param {Event} event
     */
    _handleOpen(event) {
        if (this._manuallyClosed) {
            return;
        }

        this._fallbackInvoked = false;
        this._setStatus(SseClientStatus.OPEN, { originalEvent: event });
    }

    /**
     * @param {Event} event
     */
    _handleError(event) {
        if (this._manuallyClosed || this._source === null) {
            return;
        }

        const browserClosed = this._source.readyState === 2;
        const nextStatus = browserClosed
            ? SseClientStatus.CLOSED
            : SseClientStatus.RECONNECTING;

        this._setStatus(nextStatus, {
            reason: 'connection-error',
            originalEvent: event,
        });

        this._invokeFallback('connection-error', event);
    }

    /**
     * @param {MessageEvent} event
     */
    _handleDefaultMessage(event) {
        this._dispatchMessage(GLOBAL_MESSAGE_EVENT, event);
    }

    /**
     * @param {string} eventName
     */
    _attachNamedMessageEvent(eventName) {
        if (
            this._source === null
            || this._nativeMessageHandlers.has(eventName)
        ) {
            return;
        }

        const nativeHandler = (event) => {
            this._dispatchMessage(eventName, event);
        };

        this._nativeMessageHandlers.set(eventName, nativeHandler);
        this._source.addEventListener(eventName, nativeHandler);
    }

    /**
     * @param {string} eventName
     */
    _detachNamedMessageEvent(eventName) {
        const nativeHandler = this._nativeMessageHandlers.get(eventName);

        if (nativeHandler === undefined) {
            return;
        }

        if (this._source !== null) {
            this._source.removeEventListener(eventName, nativeHandler);
        }

        this._nativeMessageHandlers.delete(eventName);
    }

    /**
     * @param {string} eventName
     * @param {MessageEvent} event
     */
    _dispatchMessage(eventName, event) {
        const message = this._normalizeMessage(eventName, event);

        if (eventName !== GLOBAL_MESSAGE_EVENT) {
            this._notify(eventName, message);
        }

        this._notify(GLOBAL_MESSAGE_EVENT, message);
    }

    /**
     * @param {string} eventName
     * @param {MessageEvent} event
     * @returns {SseMessage}
     */
    _normalizeMessage(eventName, event) {
        const raw = typeof event.data === 'string' ? event.data : '';
        let value = raw;
        let parsed = false;
        let parseError = null;

        try {
            value = JSON.parse(raw);
            parsed = true;
        } catch (error) {
            parseError = error;
        }

        const envelope = (
            parsed
            && value !== null
            && typeof value === 'object'
            && !Array.isArray(value)
        )
            ? value
            : null;

        const hasEnvelopeData = (
            envelope !== null
            && Object.prototype.hasOwnProperty.call(envelope, 'data')
        );

        return {
            id: (
                envelope !== null && typeof envelope.id === 'string'
                    ? envelope.id
                    : event.lastEventId || null
            ),
            event: (
                envelope !== null && typeof envelope.event === 'string'
                    ? envelope.event
                    : eventName
            ),
            channel: (
                envelope !== null && typeof envelope.channel === 'string'
                    ? envelope.channel
                    : null
            ),
            data: hasEnvelopeData ? envelope.data : value,
            occurredAt: (
                envelope !== null && typeof envelope.occurredAt === 'string'
                    ? envelope.occurredAt
                    : null
            ),
            version: (
                envelope !== null && Number.isInteger(envelope.version)
                    ? envelope.version
                    : null
            ),
            raw,
            parsed,
            parseError,
            originalEvent: event,
        };
    }

    /**
     * @param {string} status
     * @param {Object} [details]
     */
    _setStatus(status, details = {}) {
        if (this._status === status) {
            return;
        }

        const previous = this._status;
        this._status = status;

        this._notify(STATUS_EVENT, {
            status,
            previous,
            client: this,
            ...details,
        });
    }

    /**
     * @param {string} reason
     * @param {Event|null} [event]
     * @param {*} [error]
     * @returns {boolean}
     */
    _invokeFallback(reason, event = null, error = null) {
        if (this._fallback === null) {
            return false;
        }

        if (this._fallbackInvoked) {
            return true;
        }

        this._fallbackInvoked = true;

        try {
            const result = this._fallback({
                reason,
                event,
                error,
                status: this._status,
                url: this._currentUrl,
                client: this,
            });

            if (result !== null && typeof result?.catch === 'function') {
                result.catch((fallbackError) => {
                    this._reportHandlerError(fallbackError);
                });
            }
        } catch (fallbackError) {
            this._reportHandlerError(fallbackError);
        }

        return true;
    }

    _teardownSource() {
        this._clearRefreshTimer();

        if (this._source === null) {
            this._nativeMessageHandlers.clear();

            return;
        }

        this._source.removeEventListener('open', this._handleOpen);
        this._source.removeEventListener('error', this._handleError);
        this._source.removeEventListener(
            GLOBAL_MESSAGE_EVENT,
            this._handleDefaultMessage,
        );

        for (const [eventName, handler] of this._nativeMessageHandlers) {
            this._source.removeEventListener(eventName, handler);
        }

        this._nativeMessageHandlers.clear();
        this._source.close();
        this._source = null;
    }

    _clearRefreshTimer() {
        if (this._refreshTimer === null) {
            return;
        }

        clearTimeout(this._refreshTimer);
        this._refreshTimer = null;
    }

    _cancelAdapter() {
        if (typeof this.adapter.cancel === 'function') {
            this.adapter.cancel();
        }
    }

    /**
     * @param {string} eventName
     * @param {*} payload
     */
    _notify(eventName, payload) {
        const handlers = this._listeners.get(eventName);

        if (handlers === undefined) {
            return;
        }

        for (const handler of [...handlers]) {
            try {
                handler(payload);
            } catch (error) {
                this._reportHandlerError(error);
            }
        }
    }

    /**
     * @param {*} error
     */
    _reportHandlerError(error) {
        if (
            typeof globalThis !== 'undefined'
            && typeof globalThis.reportError === 'function'
        ) {
            globalThis.reportError(error);

            return;
        }

        if (
            typeof globalThis !== 'undefined'
            && globalThis.console !== undefined
            && typeof globalThis.console.error === 'function'
        ) {
            globalThis.console.error(error);
        }
    }

    /**
     * @param {string} eventName
     */
    _assertEventName(eventName) {
        if (
            typeof eventName !== 'string'
            || eventName.trim() === ''
            || eventName !== eventName.trim()
        ) {
            throw new TypeError('SseClient event name must be a non-empty string.');
        }

        if (RESERVED_NATIVE_EVENTS.has(eventName)) {
            throw new TypeError(
                `Use the "${STATUS_EVENT}" event for EventSource ${eventName} state.`,
            );
        }
    }

    /**
     * @param {string} eventName
     * @returns {boolean}
     */
    _isNamedMessageEvent(eventName) {
        return (
            eventName !== GLOBAL_MESSAGE_EVENT
            && eventName !== STATUS_EVENT
            && !RESERVED_NATIVE_EVENTS.has(eventName)
        );
    }
}

export default SseClient;
