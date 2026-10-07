let initialized = false;

export const initPwa = () => {
    if (initialized || !('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }
    initialized = true;

    const register = async () => {
        try {
            const baseValue = document.querySelector('meta[name="app-base-url"]')?.content;
            if (!baseValue) {
                return;
            }

            const appBase = new URL(baseValue, window.location.origin);
            if (appBase.origin !== window.location.origin) {
                return;
            }
            appBase.pathname = `${appBase.pathname.replace(/\/$/, '')}/`;
            appBase.search = '';
            appBase.hash = '';

            const registration = await navigator.serviceWorker.register(new URL('sw.js', appBase).href, {
                scope: appBase.pathname,
                updateViaCache: 'none',
            });
            await navigator.serviceWorker.ready;

            const cacheAssets = () => {
                const assets = [...document.querySelectorAll('script[src], link[rel="stylesheet"][href], link[rel="modulepreload"][href]')]
                    .map((element) => element.src || element.href)
                    .filter((value) => {
                        const url = new URL(value, appBase);
                        return url.origin === appBase.origin
                            && !url.search
                            && url.pathname.startsWith(new URL('build/assets/', appBase).pathname);
                    });

                const worker = registration.active || navigator.serviceWorker.controller;
                worker?.postMessage({ type: 'CACHE_STATIC_ASSETS', urls: [...new Set(assets)] });
            };

            cacheAssets();
            navigator.serviceWorker.addEventListener('controllerchange', cacheAssets);
        } catch {
            document.dispatchEvent(new CustomEvent('pwa:unavailable'));
        }
    };

    if (document.readyState === 'complete') {
        register();
    } else {
        window.addEventListener('load', register, { once: true });
    }
};
