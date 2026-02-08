<head>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Prevent double-initialization if the wrapper is injected more than once.
    if (window.__serverbackgroundsInitialized) return;
    window.__serverbackgroundsInitialized = true;

    const EXT_BASE = '/extensions/serverbackgrounds';
    const DASHBOARD_PATH = '/';

    // NOTE: This selector is based on Pterodactyl's generated class names.
    // If the panel updates and backgrounds stop applying, this is the first thing to check.
    const SERVER_CARD_SELECTOR = '.dyLna-D';
    const BACKGROUND_CLASS = 'background-image';

    const debounce = (func, delay) => {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => func(...args), delay);
        };
    };

    const removeAllBackgrounds = () => {
        document.querySelectorAll(`.${BACKGROUND_CLASS}`).forEach((el) => el.remove());
    };

    const isDashboard = () => window.location.pathname === DASHBOARD_PATH;

    const getPage = () => {
        const params = new URLSearchParams(window.location.search);
        return params.get('page') || '1';
    };

    const isAdminToggleEnabled = () => {
        const toggle = document.querySelector('input[name="show_all_servers"]');
        return Boolean(toggle && toggle.checked);
    };

    // Cached API data so we don't refetch on every DOM mutation.
    let cacheKey = null;
    let serversByIdentifier = new Map();
    let eggBackgroundsById = new Map();
    let serverBackgroundsByUuid = new Map();
    let fetchInFlight = null;

    const resetCache = () => {
        cacheKey = null;
        serversByIdentifier = new Map();
        eggBackgroundsById = new Map();
        serverBackgroundsByUuid = new Map();
    };

    const loadSettings = async () => {
        try {
            const r = await fetch(`${EXT_BASE}/api/settings`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
            });

            if (!r.ok) return { disable_for_admins: false, user_is_admin: false };
            return await r.json();
        } catch {
            return { disable_for_admins: false, user_is_admin: false };
        }
    };

    const fetchData = async () => {
        const page = getPage();
        const adminMode = isAdminToggleEnabled();
        const key = `${page}:${adminMode ? 'admin' : 'user'}`;

        if (cacheKey === key) return;
        if (fetchInFlight) return await fetchInFlight;

        const apiUrl = adminMode
            ? `/api/client?page=${encodeURIComponent(page)}&type=admin`
            : `/api/client?page=${encodeURIComponent(page)}`;

        fetchInFlight = Promise.all([
            fetch(apiUrl).then((r) => r.json()),
            fetch(`${EXT_BASE}/configured-egg-backgrounds`).then((r) => r.json()),
            fetch(`${EXT_BASE}/configured-server-backgrounds`).then((r) => r.json()),
        ])
            .then(([serverData, configuredEggs, configuredServerBackgrounds]) => {
                cacheKey = key;
                serversByIdentifier = new Map();
                eggBackgroundsById = new Map();
                serverBackgroundsByUuid = new Map();

                if (serverData && Array.isArray(serverData.data)) {
                    for (const server of serverData.data) {
                        const attrs = server?.attributes;
                        const identifier = attrs?.identifier;
                        const uuid = attrs?.uuid;
                        const eggId = attrs?.BlueprintFramework?.egg_id;

                        if (typeof identifier === 'string' && typeof uuid === 'string' && eggId !== undefined && eggId !== null) {
                            serversByIdentifier.set(identifier, { uuid, egg_id: eggId });
                        }
                    }
                }

                if (Array.isArray(configuredEggs)) {
                    for (const egg of configuredEggs) {
                        const id = egg?.id;
                        const url = egg?.image_url;
                        if (id === undefined || typeof url !== 'string' || url.trim() === '') continue;

                        eggBackgroundsById.set(String(id), {
                            image_url: url,
                            opacity: egg?.opacity,
                        });
                    }
                }

                if (Array.isArray(configuredServerBackgrounds)) {
                    for (const bg of configuredServerBackgrounds) {
                        const uuid = bg?.uuid;
                        const url = bg?.image_url;
                        if (typeof uuid !== 'string' || typeof url !== 'string' || url.trim() === '') continue;

                        serverBackgroundsByUuid.set(uuid, {
                            image_url: url,
                            opacity: bg?.opacity,
                        });
                    }
                }
            })
            .finally(() => {
                fetchInFlight = null;
            });

        return await fetchInFlight;
    };

    const clampOpacity = (value) => {
        const n = Number(value);
        if (!Number.isFinite(n)) return 1;
        return Math.max(0, Math.min(1, n));
    };

    const applyBackgrounds = debounce(async () => {
        if (!isDashboard()) return;

        await fetchData();

        document.querySelectorAll(SERVER_CARD_SELECTOR).forEach((container) => {
            const href = container.getAttribute('href');
            if (!href) return;

            const parts = href.split('/').filter(Boolean);
            const identifier = parts.length ? parts[parts.length - 1] : null;
            if (!identifier) return;

            const server = serversByIdentifier.get(identifier);
            if (!server) return;

            const serverBg = serverBackgroundsByUuid.get(server.uuid);
            const eggBg = eggBackgroundsById.get(String(server.egg_id));
            const chosen = serverBg || eggBg;

            const imageUrl = chosen?.image_url;
            const existing = container.querySelector(`.${BACKGROUND_CLASS}`);

            if (!imageUrl || typeof imageUrl !== 'string') {
                if (existing) existing.remove();
                return;
            }

            // Ensure the card creates a stacking context so the background can sit behind content.
            container.style.position = 'relative';
            container.style.overflow = 'hidden';
            container.style.zIndex = '0';

            const opacity = clampOpacity(chosen?.opacity);
            const bgKey = `${imageUrl}|${opacity}`;

            let bgEl = existing;
            if (!bgEl) {
                bgEl = document.createElement('div');
                bgEl.className = BACKGROUND_CLASS;
                container.prepend(bgEl);
            }

            if (bgEl.dataset.bgKey === bgKey) return;
            bgEl.dataset.bgKey = bgKey;

            // Intentionally no cache busting: letting the browser cache images is a big performance win.
            bgEl.style.backgroundImage = `url('${imageUrl}')`;
            bgEl.style.backgroundSize = 'cover';
            bgEl.style.backgroundPosition = 'center center';
            bgEl.style.opacity = String(opacity);
            bgEl.style.position = 'absolute';
            bgEl.style.top = '0';
            bgEl.style.left = '0';
            bgEl.style.right = '0';
            bgEl.style.bottom = '0';
            bgEl.style.zIndex = '-1';
            bgEl.style.pointerEvents = 'none';
        });
    }, 250);

    const emitNav = () => window.dispatchEvent(new Event('serverbackgrounds:navigation'));

    const patchHistory = () => {
        const wrap = (method) => {
            const original = history[method];
            if (typeof original !== 'function') return;

            history[method] = function (...args) {
                const result = original.apply(this, args);
                emitNav();
                return result;
            };
        };

        wrap('pushState');
        wrap('replaceState');
    };

    let observer = null;

    const attachObserver = () => {
        if (observer) return;
        const appDiv = document.querySelector('#app');
        if (!appDiv) return;

        observer = new MutationObserver(() => {
            if (!isDashboard()) return;
            applyBackgrounds();
        });

        observer.observe(appDiv, { childList: true, subtree: true });
    };

    const detachObserver = () => {
        if (!observer) return;
        observer.disconnect();
        observer = null;
    };

    const onNavigation = () => {
        if (!isDashboard()) {
            detachObserver();
            return;
        }

        attachObserver();
        applyBackgrounds();
    };

    (async () => {
        const settings = await loadSettings();
        const disabledForUser = settings?.disable_for_admins === true && settings?.user_is_admin === true;

        if (disabledForUser) {
            removeAllBackgrounds();
            return;
        }

        patchHistory();

        window.addEventListener('serverbackgrounds:navigation', onNavigation);
        window.addEventListener('popstate', emitNav);

        // Listen for the Blueprint admin toggle (if present) to switch API sources.
        document.addEventListener('change', (event) => {
            const target = event.target;
            if (target instanceof HTMLInputElement && target.name === 'show_all_servers') {
                resetCache();
                removeAllBackgrounds();
                onNavigation();
            }
        });

        onNavigation();
    })();
});
</script>
<style>
.background-image {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
}
</style>
</head>