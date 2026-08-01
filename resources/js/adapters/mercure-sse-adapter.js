const DEFAULT_TIMEOUT_MILLISECONDS = 15_000;

export class MercureSseAdapter {
    constructor({
        fetchFactory = null,
        timeout = DEFAULT_TIMEOUT_MILLISECONDS,
    } = {}) {
        if (fetchFactory !== null && typeof fetchFactory !== 'function') {
            throw new TypeError(
                'MercureSseAdapter fetchFactory must be a function or null.',
            );
        }

        if (
            !Number.isFinite(timeout)
            || timeout <= 0
            || timeout > 2_147_483_647
        ) {
            throw new TypeError(
                'MercureSseAdapter timeout must be a positive finite number.',
            );
        }

        this._fetchFactory = fetchFactory;
        this._timeout = timeout;
        this._controller = null;
        this._timeoutId = null;
    }

    /**
     * @param {{url: string, withCredentials: boolean}} context
     * @returns {Promise<{url: string, expiresAt: number|null}>}
     */
    async resolve({ url, withCredentials }) {
        const fetchFactory = this._resolveFetchFactory();

        if (fetchFactory === null) {
            throw new Error('Fetch is required by the Mercure SSE adapter.');
        }

        this.cancel();

        const controller = this._createAbortController();
        this._controller = controller;

        try {
            const response = await Promise.race([
                fetchFactory(url, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: withCredentials ? 'include' : 'same-origin',
                    cache: 'no-store',
                    ...(controller === null ? {} : { signal: controller.signal }),
                }),
                this._timeoutPromise(controller),
            ]);

            const bootstrap = await this._readJsonResponse(response);
            const authorization = this._normalizeBootstrap(bootstrap);

            return {
                url: this._buildHubUrl(authorization.hub, authorization.topics),
                expiresAt: authorization.expiresAt,
            };
        } finally {
            this._clearTimeout();

            if (this._controller === controller) {
                this._controller = null;
            }
        }
    }

    cancel() {
        this._controller?.abort();
        this._controller = null;
        this._clearTimeout();
    }

    _resolveFetchFactory() {
        if (this._fetchFactory !== null) {
            return this._fetchFactory;
        }

        if (
            typeof globalThis === 'undefined'
            || typeof globalThis.fetch !== 'function'
        ) {
            return null;
        }

        return globalThis.fetch.bind(globalThis);
    }

    _createAbortController() {
        if (
            typeof globalThis === 'undefined'
            || typeof globalThis.AbortController !== 'function'
        ) {
            return null;
        }

        return new globalThis.AbortController();
    }

    _timeoutPromise(controller) {
        return new Promise((resolve, reject) => {
            this._timeoutId = setTimeout(() => {
                controller?.abort();
                reject(new Error('Mercure authorization timed out.'));
            }, this._timeout);

            this._timeoutId?.unref?.();
        });
    }

    async _readJsonResponse(response) {
        if (
            response === null
            || typeof response !== 'object'
            || typeof response.json !== 'function'
        ) {
            throw new TypeError(
                'The Mercure authorization endpoint returned an invalid response.',
            );
        }

        if (response.ok !== true) {
            throw new Error(
                `Mercure authorization failed with HTTP ${response.status ?? 0}.`,
            );
        }

        try {
            return await response.json();
        } catch (error) {
            throw new TypeError(
                'The Mercure authorization endpoint returned invalid JSON.',
                { cause: error },
            );
        }
    }

    /**
     * @param {*} bootstrap
     * @returns {{hub: string, topics: string[], expiresAt: number|null}}
     */
    _normalizeBootstrap(bootstrap) {
        const expiresAt = bootstrap?.expiresAt ?? null;

        if (
            bootstrap === null
            || typeof bootstrap !== 'object'
            || typeof bootstrap.hub !== 'string'
            || bootstrap.hub.trim() === ''
            || !Array.isArray(bootstrap.topics)
            || bootstrap.topics.length === 0
            || bootstrap.topics.some((topic) => (
                typeof topic !== 'string' || topic.trim() === ''
            ))
            || (
                expiresAt !== null
                && (
                    !Number.isSafeInteger(expiresAt)
                    || expiresAt <= Math.floor(Date.now() / 1000)
                )
            )
        ) {
            throw new TypeError(
                'The Mercure authorization endpoint returned invalid bootstrap data.',
            );
        }

        return {
            hub: bootstrap.hub.trim(),
            topics: [...new Set(
                bootstrap.topics.map((topic) => topic.trim()),
            )],
            expiresAt,
        };
    }

    /**
     * @param {string} hub
     * @param {string[]} topics
     * @returns {string}
     */
    _buildHubUrl(hub, topics) {
        let url;

        try {
            url = new URL(hub);
        } catch (error) {
            throw new TypeError(
                'The Mercure Hub URL must be absolute.',
                { cause: error },
            );
        }

        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            throw new TypeError('The Mercure Hub URL must use HTTP or HTTPS.');
        }

        url.searchParams.delete('topic');

        for (const topic of topics) {
            url.searchParams.append('topic', topic);
        }

        url.hash = '';

        return url.toString();
    }

    _clearTimeout() {
        if (this._timeoutId === null) {
            return;
        }

        clearTimeout(this._timeoutId);
        this._timeoutId = null;
    }
}

export default MercureSseAdapter;
