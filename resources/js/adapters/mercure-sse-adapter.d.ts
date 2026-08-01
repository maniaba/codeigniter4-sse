import type {
    MercureSseAdapterOptions,
    SseAdapter,
    SseAdapterConnection,
    SseAdapterContext,
} from '../sse-client.js';

export declare class MercureSseAdapter implements SseAdapter {
    constructor(options?: MercureSseAdapterOptions);
    resolve(context: SseAdapterContext): Promise<SseAdapterConnection>;
    cancel(): void;
}

export default MercureSseAdapter;
