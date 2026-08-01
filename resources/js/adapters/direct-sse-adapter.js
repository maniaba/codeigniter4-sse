export class DirectSseAdapter {
    /**
     * @param {{url: string}} context
     * @returns {{url: string, expiresAt: null}}
     */
    resolve({ url }) {
        return { url, expiresAt: null };
    }

    cancel() {
    }
}

export default DirectSseAdapter;
