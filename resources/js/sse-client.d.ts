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
    | 'connection-error';

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

export interface SseClientOptions {
    /**
     * Absolute or browser-relative SSE endpoint.
     */
    readonly endpoint: string;

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
     * Optional application fallback for unsupported browsers or connection errors.
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
     * Open the EventSource connection. Repeated calls are idempotent while active.
     */
    connect(): this;

    /**
     * Close the active EventSource. Registered handlers remain available.
     */
    close(): this;
}

export default SseClient;
