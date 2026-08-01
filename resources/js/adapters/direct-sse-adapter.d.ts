import type {
    SseAdapter,
    SseAdapterConnection,
    SseAdapterContext,
} from '../sse-client.js';

export declare class DirectSseAdapter implements SseAdapter {
    resolve(context: SseAdapterContext): SseAdapterConnection;
    cancel(): void;
}

export default DirectSseAdapter;
