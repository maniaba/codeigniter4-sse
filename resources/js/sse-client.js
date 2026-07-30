/**
 * Small EventSource wrapper for maniaba/codeigniter4-sse.
 *
 * The browser owns reconnect timing. This client does not create a competing
 * retry loop; it reports the reconnecting status while the native EventSource
 * follows the server's `retry` hint.
 */

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
     * Repeated calls while a source is active are idempotent.
     *
     * @returns {SseClient}
     */
    connect() {
        if (
            this._source !== null
            && this._status !== SseClientStatus.CLOSED
            && this._status !== SseClientStatus.UNSUPPORTED
        ) {
            return this;
        }

        if (this._source !== null) {
            this._teardownSource();
        }

        this._manuallyClosed = false;
        this._fallbackInvoked = false;

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

            return this;
        }

        this._currentUrl = this._buildUrl();
        this._setStatus(SseClientStatus.CONNECTING);

        let source;

        try {
            source = factory(this._currentUrl, {
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

            if (!handled) {
                throw error;
            }

            return this;
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

            if (!handled) {
                throw error;
            }

            return this;
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
        this._teardownSource();
        this._setStatus(SseClientStatus.CLOSED, { reason: 'manual' });

        return this;
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
     * @returns {boolean}
     */
    _hasActiveSource() {
        return (
            this._source !== null
            && this._status !== SseClientStatus.CLOSED
            && this._status !== SseClientStatus.UNSUPPORTED
        );
    }

    _restartForChannelChange() {
        if (!this._hasActiveSource()) {
            return;
        }

        this._teardownSource();

        if (this.channels.length === 0) {
            this._setStatus(SseClientStatus.CLOSED, {
                reason: 'channels-empty',
            });

            return;
        }

        this.connect();
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
