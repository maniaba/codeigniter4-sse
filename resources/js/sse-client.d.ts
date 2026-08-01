/**
 * Lifecycle states reported by SseClient.
 */
export declare const SseClientStatus: Readonly<{
    readonly IDLE: 'idle';
    readonly CONNECTING: 'connecting';
    readonly OPEN: 'open';
    readonly RECONNECTING: 'reconnecting';
    readonly CLOSED: 'closed';
    readonly UNSUPPORTED: 'unsupported';
}>;

export type SseClientStatusValue =
    (typeof SseClientStatus)[keyof typeof SseClientStatus];

/**
 * Query parameters merged into the EventSource URL.
 */
export type SseQueryValue =
    | string
    | number
    | boolean
    | null
    | undefined;

export type SseQuery =
    | URLSearchParams
    | Record<string, SseQueryValue | SseQueryValue[]>;

export type SseChannelInput = string | readonly string[];

export interface SseAdapterContext {
    readonly url: string;
    readonly channels: readonly string[];
    readonly withCredentials: boolean;
    readonly client: SseClient;
}

export interface SseAdapterConnection {
    readonly url: string;
    readonly expiresAt?: number | null;
}

export interface SseAdapter {
    resolve(
        context: SseAdapterContext,
    ): SseAdapterConnection | PromiseLike<SseAdapterConnection>;
    cancel?(): void;
}

export interface MercureSseAdapterOptions {
    /**
     * Optional Fetch-compatible factory, mainly useful for tests.
     */
    readonly fetchFactory?: SseFetchFactory | null;

    /**
     * Authorization request timeout in milliseconds.
     */
    readonly timeout?: number;
}

/**
 * Direct EventSource adapter for PHP-streamed brokers.
 */
export declare class DirectSseAdapter implements SseAdapter {
    resolve(context: SseAdapterContext): SseAdapterConnection;
    cancel(): void;
}

/**
 * Semantic alias for the package local broker.
 */
export declare class LocalSseAdapter extends DirectSseAdapter {
}

/**
 * Semantic alias for Redis-backed PHP streaming.
 */
export declare class RedisSseAdapter extends DirectSseAdapter {
}

/**
 * Semantic alias for the in-memory PHP stream broker.
 */
export declare class InMemorySseAdapter extends DirectSseAdapter {
}

/**
 * Resolves the package Mercure authorization endpoint to a Hub EventSource URL.
 */
export declare class MercureSseAdapter implements SseAdapter {
    constructor(options?: MercureSseAdapterOptions);
    resolve(context: SseAdapterContext): Promise<SseAdapterConnection>;
    cancel(): void;
}

/**
 * Parsed message delivered to named event handlers and global message handlers.
 */
export interface SseMessage<TData = unknown> {
    /**
     * Event ID from the package envelope or native MessageEvent.lastEventId.
     */
    readonly id: string | null;

    /**
     * Event name from the package envelope or the observed EventSource event.
     */
    readonly event: string;

    /**
     * Logical SSE channel from the package envelope.
     */
    readonly channel: string | null;

    /**
     * Application payload. For invalid JSON, this is the raw event data string.
     */
    readonly data: TData;

    /**
     * ISO-8601 occurrence timestamp from the package envelope.
     */
    readonly occurredAt: string | null;

    /**
     * Package envelope version.
     */
    readonly version: number | null;

    /**
     * Raw MessageEvent.data value.
     */
    readonly raw: string;

    /**
     * True when raw data was valid JSON.
     */
    readonly parsed: boolean;

    /**
     * JSON parse error when parsed is false.
     */
    readonly parseError: Error | null;

    /**
     * Original browser MessageEvent.
     */
    readonly originalEvent: MessageEvent;
}

export type SseMessageHandler<TData = unknown> = (
    message: SseMessage<TData>,
) => void;

export interface SseStatusEvent {
    readonly status: SseClientStatusValue;
    readonly previous: SseClientStatusValue;
    readonly client: SseClient;
    readonly reason?: string;
    readonly url?: string | null;
    readonly error?: unknown;
    readonly originalEvent?: Event;
}

export type SseStatusHandler = (event: SseStatusEvent) => void;

export type SseFallbackReason =
    | 'unsupported'
    | 'construction-error'
    | 'connection-error'
    | 'adapter-error';

export interface SseFallbackContext {
    readonly reason: SseFallbackReason;
    readonly event: Event | null;
    readonly error: unknown;
    readonly status: SseClientStatusValue;
    readonly url: string | null;
    readonly client: SseClient;
}

export type SseFallback = (
    context: SseFallbackContext,
) => void | Promise<void> | unknown;

/**
 * Minimal EventSource-compatible object used by tests or custom factories.
 */
export interface SseEventSourceLike {
    readonly readyState?: number;
    addEventListener(type: string, listener: EventListenerOrEventListenerObject): void;
    removeEventListener(type: string, listener: EventListenerOrEventListenerObject): void;
    close(): void;
}

export type SseEventSourceFactory = (
    url: string,
    options: EventSourceInit,
) => SseEventSourceLike;

export type SseFetchFactory = (
    input: RequestInfo | URL,
    init?: RequestInit,
) => Response | PromiseLike<Response>;

export interface SseClientOptions {
    /**
     * Absolute or browser-relative SSE endpoint.
     */
    readonly endpoint: string;

    /**
     * Broker adapter. Defaults to DirectSseAdapter.
     */
    readonly adapter?: SseAdapter | null;

    /**
     * Logical channel names sent as the channels query parameter.
     */
    readonly channels?: readonly string[];

    /**
     * Additional query parameters merged into the endpoint URL.
     */
    readonly query?: SseQuery;

    /**
     * Passed to native EventSource for credentialed CORS/cookie requests.
     */
    readonly withCredentials?: boolean;

    /**
     * Optional application fallback for adapter, connection, or browser errors.
     */
    readonly fallback?: SseFallback | null;

    /**
     * Optional EventSource factory, mainly useful for tests.
     */
    readonly eventSourceFactory?: SseEventSourceFactory | null;
}

export declare class SseClient {
    constructor(options?: SseClientOptions);

    readonly endpoint: string;
    readonly adapter: SseAdapter;
    readonly channels: string[];
    readonly query: SseQuery;
    readonly withCredentials: boolean;

    /**
     * Current lifecycle status.
     */
    get status(): SseClientStatusValue;

    /**
     * URL used by the active or most recent EventSource connection.
     */
    get url(): string | null;

    /**
     * Register a lifecycle status handler.
     */
    on(eventName: 'status', handler: SseStatusHandler): this;

    /**
     * Register a global message handler. It receives default messages and
     * each named event that this client is observing.
     */
    on<TData = unknown>(
        eventName: 'message',
        handler: SseMessageHandler<TData>,
    ): this;

    /**
     * Register a named SSE event handler.
     */
    on<TData = unknown>(
        eventName: string,
        handler: SseMessageHandler<TData>,
    ): this;

    /**
     * Remove one handler, or all handlers for the event when handler is omitted.
     */
    off(eventName: 'status', handler?: SseStatusHandler): this;

    /**
     * Remove one global message handler, or all global message handlers.
     */
    off<TData = unknown>(
        eventName: 'message',
        handler?: SseMessageHandler<TData>,
    ): this;

    /**
     * Remove one named event handler, or all handlers for that event.
     */
    off<TData = unknown>(
        eventName: string,
        handler?: SseMessageHandler<TData>,
    ): this;

    /**
     * Convenience alias for on('message', handler).
     */
    onMessage<TData = unknown>(handler: SseMessageHandler<TData>): this;

    /**
     * Replace all subscribed channels. Active connections are restarted with
     * the new channel query. An empty array removes the channels parameter.
     */
    setChannels(channels: SseChannelInput): this;

    /**
     * Add one or more channels. Active connections are restarted with the
     * expanded channel query.
     */
    subscribe(channels: SseChannelInput): this;

    /**
     * Remove one or more channels. Active connections are restarted with the
     * remaining channels. Removing the last channel closes the stream.
     */
    unsubscribe(channels: SseChannelInput): this;

    /**
     * Open the EventSource connection. Repeated calls are idempotent while
     * active.
     */
    connect(): this;

    /**
     * Close the active EventSource. Registered handlers remain available.
     */
    close(): this;
}

export default SseClient;
