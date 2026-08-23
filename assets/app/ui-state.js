/**
 * UI State Orchestration Module
 * 
 * Coordinates all the other modules and manages the overall app state and lifecycle.
 * Handles transitions between loading, error, list, and detail states.
 */

import { readShellConfig, BootConfigError } from './shell-config.js';
import { loadSnapshot, getMetric } from './snapshot-loader.js';
import { parseRouterState, pushRouterState, replaceRouterState } from './router-state.js';
import {
    normalizeQueryState,
    applyFilters,
    collectShellVersionOptions,
    filterByShellVersion,
    normalizeShellVersion,
} from './filter-engine.js';
import {
    renderLoadingState,
    renderBootErrorState,
    renderLoadErrorState,
    renderNotFoundState,
} from './status-view.js';
import { renderListView } from './list-view.js';
import { renderDetailView, renderCommentsSection } from './detail-view.js';
import { loadComments, getCommentsForExtension, deriveCommentsUrl } from './comments-loader.js';

let lastRenderedView = null;
let savedListScrollY = 0;
let hasSavedListScrollY = false;
let currentListPage = 1;

/**
 * The compatibility selection is a device preference, not a shareable view:
 * it lives in localStorage only and never becomes a query parameter, so
 * shared URLs keep showing the full index.
 */
const SHELL_VERSION_STORAGE_KEY = 'eh.shellVersion';

// Selected shell version ("50") or null for "Any GNOME version".
let selectedShellVersion = null;

/**
 * Initialize and run the extensions app.
 * 
 * @param {Document} document - The DOM document
 * @param {Window} window - The browser window object
 */
export async function initializeApp(document, window) {
    currentListPage = 1;
    // A stale lightbox state must never lock page scrolling after a reload or
    // browser history restore.
    document.body.classList.remove('glightbox-open');
    document.documentElement.classList.remove('glightbox-open');
    document.body.style.removeProperty('overflow');
    document.documentElement.style.removeProperty('overflow');
    let config;
    let snapshot = null;
    let mainContainer = null;

    // Step 1: Boot configuration
    try {
        config = readShellConfig(document);
        mainContainer = config.mountElement.parentElement || document.body;
        const initialRouter = parseRouterState(window.location);
        if (Object.prototype.hasOwnProperty.call(initialRouter.query, 'page')) {
            replaceRouterState(initialRouter.pathname, cleanQuery(initialRouter.query));
        }
    } catch (error) {
        if (error instanceof BootConfigError) {
            renderBootErrorState(config?.mountElement || document.body, error);
        }
        return;
    }

    // Step 2: Load snapshot
    renderLoadingState(config.mountElement);

    try {
        snapshot = await loadSnapshot(config.feedUrl);
        establishScoreOrder(snapshot);
        selectedShellVersion = restoreShellVersion(window, snapshot);
    } catch (error) {
        const retryFn = async () => {
            renderLoadingState(config.mountElement);
            try {
                // Retry with cache-busting
                snapshot = await loadSnapshot(config.feedUrl, { cache: 'no-cache' });
                establishScoreOrder(snapshot);
                selectedShellVersion = restoreShellVersion(window, snapshot);
                renderApp(config, snapshot, document, window);
            } catch (retryError) {
                renderLoadErrorState(config.mountElement, retryError, retryFn);
            }
        };
        renderLoadErrorState(config.mountElement, error, retryFn);
        return;
    }

    // Step 3: Render the app
    bindHeaderSearch(document, window, () => ({ config, snapshot }));
    renderApp(config, snapshot, document, window, {});
}

/**
 * The search input lives in the static page header, outside the #app mount,
 * so it is bound once here instead of per list render. Typing navigates to
 * the list view with the search term applied.
 */
function bindHeaderSearch(document, window, getContext) {
    const input = document.querySelector('#search-input');
    if (!input || input.dataset.ehSearchBound === '1') {
        return;
    }

    input.dataset.ehSearchBound = '1';
    input.addEventListener('input', (event) => {
        const { config, snapshot } = getContext();
        const router = parseRouterState(window.location);
        // A new search starts from relevance instead of inheriting a list sort.
        const newQuery = cleanQuery({ ...router.query, search: event.target.value, sort_by: undefined });

        replaceRouterState('/', newQuery, 'Extension Hub - Discover GNOME Shell Extensions');
        renderApp(config, snapshot, document, window, {});
        queueScroll(window, 0);
    });
}

/**
 * Read the stored compatibility selection and validate it against the
 * versions this snapshot actually declares. A stale, unknown or unreadable
 * value degrades to "Any GNOME version" instead of hiding the whole index.
 *
 * @param {Window} window - The browser window object
 * @param {Object} snapshot - The extensions snapshot
 * @returns {string|null} A selectable shell version or null
 */
function restoreShellVersion(window, snapshot) {
    const stored = readStoredShellVersion(window);
    const restored = normalizeShellVersion(stored, collectShellVersionOptions(snapshot.items));

    // Drop a rejected value instead of keeping it around: it would silently
    // start filtering again as soon as a later snapshot happens to declare it.
    if (restored === null && typeof stored === 'string') {
        storeShellVersion(window, null);
    }

    return restored;
}

/** localStorage can throw (private mode, disabled storage); never fatal. */
function readStoredShellVersion(window) {
    try {
        return window.localStorage?.getItem(SHELL_VERSION_STORAGE_KEY);
    } catch {
        return null;
    }
}

function storeShellVersion(window, shellVersion) {
    try {
        if (shellVersion === null) {
            window.localStorage?.removeItem(SHELL_VERSION_STORAGE_KEY);
            return;
        }

        window.localStorage?.setItem(SHELL_VERSION_STORAGE_KEY, shellVersion);
    } catch {
        // Selection still applies to this session; persistence is best effort.
    }
}

/**
 * v2 has no top-level downloads; `score` is the canonical popularity
 * ranking. Sorting the items once here gives every downstream consumer
 * (filter-engine sorts that degenerate to comparator equality, discover
 * section pools) a deterministic score-descending base order, because
 * Array#sort is stable.
 */
function establishScoreOrder(snapshot) {
    snapshot.items = [...snapshot.items].sort((a, b) => (b.score ?? 0) - (a.score ?? 0));
}

/**
 * Render the app based on current router state.
 * 
 * @param {Object} config - App configuration
 * @param {Object} snapshot - The extensions snapshot
 * @param {Document} document - The DOM document
 * @param {Window} window - The browser window object
 */
function renderApp(config, snapshot, document, window, ui = {}) {
    const router = parseRouterState(window.location);
    const query = router.query;
    const effectiveFilterState = {
        ...normalizeQueryState(query, snapshot.pageSize),
        page: currentListPage,
    };
    const latestGnomeVersion = findLatestGnomeVersion(snapshot.items);

    // The compatibility selection gates the item pool before anything else,
    // so Discover, sidebar counts and every list agree on what exists. The
    // option list stays derived from the full snapshot, otherwise selecting
    // a version would shrink the panel it was chosen from.
    const shellVersionOptions = collectShellVersionOptions(snapshot.items);
    const compatibleItems = filterByShellVersion(snapshot.items, selectedShellVersion);
    const compatibility = { selected: selectedShellVersion, options: shellVersionOptions };

    const container = config.mountElement;

    if (router.view === 'detail') {
        // Find the extension by UUID
        const item = snapshot.items.find(ext => ext.uuid === router.uuid);

        if (!item) {
            renderNotFoundState(container, router.uuid, () => {
                pushRouterState('/', {}, 'Extension Hub - Discover Extensions');
                renderApp(config, snapshot, document, window, {});
            });
            return;
        }

        document.title = `${item.name} - Extension Hub`;

        const relatedItems = snapshot.items
            .filter((entry) => entry.uuid !== item.uuid && entry.creator === item.creator)
            .sort((a, b) => (getMetric(b, 'ego', 'downloads') ?? b.score ?? 0) - (getMetric(a, 'ego', 'downloads') ?? a.score ?? 0))
            .slice(0, 5);

        // Render detail view immediately, then load comments asynchronously
        renderDetailView(container, item, relatedItems, {
            latestGnomeVersion,
            comments: [],
            onGoBack: () => {
                pushRouterState('/', query, 'Extension Hub - Discover Extensions');
                renderApp(config, snapshot, document, window, { restoreListScroll: true });
            },
            onOpenRelated: (uuid) => {
                pushRouterState(`/extension/${encodeURIComponent(uuid)}`, query, 'Extension Hub - Extension');
                renderApp(config, snapshot, document, window, {});
            },
        });
        window.__ehLightbox?.reload?.();
        queueScroll(window, 0);
        lastRenderedView = 'detail';

        // Comments load once per session; the reviews section renders every
        // loaded review at once and only reveals them in steps, so the
        // "show more" button never triggers another request.
        const commentsUrl = deriveCommentsUrl(config.feedUrl);
        loadComments(commentsUrl).then(() => {
            const commentsContainer = container.querySelector('[data-comments-section]');
            if (commentsContainer) {
                renderCommentsSection(commentsContainer, getCommentsForExtension(item.uuid));
            }
        });
    } else {
        // List view
        document.title = 'Extension Hub - Discover GNOME Shell Extensions';

        // "Load more" keeps earlier pages visible, so pages 1..n are requested
        // as one accumulated first page instead of only the last slice.
        const isAccumulated = effectiveFilterState.page > 1;
        const results = applyFilters(
            compatibleItems,
            isAccumulated
                ? { ...effectiveFilterState, page: 1, pageSize: effectiveFilterState.pageSize * effectiveFilterState.page }
                : effectiveFilterState,
        );

        renderListView(container, {
            items: results.items,
            filterState: effectiveFilterState,
            pagination: {
                totalCount: results.totalCount,
                // Real page size, not the accumulated one used for slicing items.
                totalPages: Math.ceil(results.totalCount / effectiveFilterState.pageSize),
                currentPage: effectiveFilterState.page,
                pageSize: effectiveFilterState.pageSize,
            },
            // List/Discover rendering only ever sees the compatible pool.
            snapshot: { ...snapshot, items: compatibleItems },
            compatibility,
            ui,
        }, {
            onFilterChange: (partialFilterState) => {
                const baseQuery = Object.prototype.hasOwnProperty.call(partialFilterState, 'preset')
                    ? {
                        ...query,
                        sort_by: undefined,
                        time_range: undefined,
                        date_field: undefined,
                        min_rating: undefined,
                        min_downloads: undefined,
                        min_gnome_version: undefined,
                    }
                    : query;

                const newQuery = {
                    ...baseQuery,
                    ...mapPartialFilterToQuery(partialFilterState),
                };
                currentListPage = 1;
                pushRouterState('/', cleanQuery(newQuery), 'Extension Hub - Discover Extensions');
                renderApp(config, snapshot, document, window, {});
                queueScroll(window, 0);
            },
            onPageChange: (page) => {
                currentListPage = page;
                pushRouterState('/', cleanQuery(query), 'Extension Hub - Discover GNOME Shell Extensions');
                renderApp(config, snapshot, document, window, {});
            },
            onItemClick: (uuid) => {
                savedListScrollY = window.scrollY || 0;
                hasSavedListScrollY = true;
                const itemPath = `/extension/${encodeURIComponent(uuid)}`;
                pushRouterState(itemPath, query, 'Extension Hub - Extension');
                renderApp(config, snapshot, document, window, {});
            },
            onClearFilters: () => {
                pushRouterState('/', {}, 'Extension Hub - Discover Extensions');
                renderApp(config, snapshot, document, window, { focusSearch: true, cursorPos: 0 });
            },
            // Deliberately no router push: the compatibility selection is a
            // stored device preference and must not appear in the URL.
            onShellVersionChange: (shellVersion) => {
                selectedShellVersion = normalizeShellVersion(shellVersion, shellVersionOptions);
                storeShellVersion(window, selectedShellVersion);
                currentListPage = 1;
                renderApp(config, snapshot, document, window, {});
                queueScroll(window, 0);
            },
        });
        window.__ehLightbox?.reload?.();

        // Header search input is static; keep it in sync with the URL state.
        const headerSearch = document.querySelector('#search-input');
        if (headerSearch && document.activeElement !== headerSearch) {
            headerSearch.value = effectiveFilterState.search || '';
        }
        if (headerSearch) {
            headerSearch.placeholder = `Search ${compatibleItems.length.toLocaleString('en-US')} extensions`;
        }

        const shouldRestoreListScroll = ui.restoreListScroll === true
            || (lastRenderedView === 'detail' && hasSavedListScrollY);

        if (shouldRestoreListScroll) {
            queueScroll(window, savedListScrollY);
            hasSavedListScrollY = false;
        }

        lastRenderedView = 'list';
    }
}

function queueScroll(window, top) {
    window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
            window.scrollTo({ top, left: 0, behavior: 'auto' });
        });
    });
}

function mapPartialFilterToQuery(partialFilterState) {
    const mapped = {};

    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'search')) {
        mapped.search = partialFilterState.search;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'category')) {
        mapped.category = partialFilterState.category;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'preset')) {
        mapped.preset = partialFilterState.preset;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'sortBy')) {
        mapped.sort_by = partialFilterState.sortBy;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'minRating')) {
        mapped.min_rating = partialFilterState.minRating;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'minDownloads')) {
        mapped.min_downloads = partialFilterState.minDownloads;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'minGnomeVersion')) {
        mapped.min_gnome_version = partialFilterState.minGnomeVersion;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'timeRange')) {
        mapped.time_range = partialFilterState.timeRange;
    }
    if (Object.prototype.hasOwnProperty.call(partialFilterState, 'dateField')) {
        mapped.date_field = partialFilterState.dateField;
    }

    return mapped;
}

function cleanQuery(query) {
    const cleaned = {};

    Object.entries(query).forEach(([key, value]) => {
        if (key === 'page' || value === null || value === undefined || value === '') {
            return;
        }

        cleaned[key] = value;
    });

    return cleaned;
}

function findLatestGnomeVersion(items) {
    let latest = null;

    for (const item of items) {
        const highest = getHighestSupportedShellVersion(item.supportedShellVersions);
        if (highest === null) {
            continue;
        }

        if (latest === null || compareShellVersions(highest, latest) > 0) {
            latest = highest;
        }
    }

    return latest;
}

function getHighestSupportedShellVersion(versions) {
    if (!Array.isArray(versions) || versions.length === 0) {
        return null;
    }

    let highest = null;

    for (const version of versions) {
        const normalized = String(version).trim();
        if (normalized === '') {
            continue;
        }

        if (highest === null || compareShellVersions(normalized, highest) > 0) {
            highest = normalized;
        }
    }

    return highest;
}

function compareShellVersions(a, b) {
    const aParts = String(a).split('.').map((part) => Number.parseInt(part, 10) || 0);
    const bParts = String(b).split('.').map((part) => Number.parseInt(part, 10) || 0);
    const maxLength = Math.max(aParts.length, bParts.length);

    for (let i = 0; i < maxLength; i++) {
        const aPart = aParts[i] ?? 0;
        const bPart = bParts[i] ?? 0;

        if (aPart > bPart) {
            return 1;
        }
        if (aPart < bPart) {
            return -1;
        }
    }

    return 0;
}
