/**
 * Filter Engine Module
 *
 * Normalizes query parameters, applies filters, searches, sorting, and pagination.
 * Client-side equivalent of the backend filter logic.
 */

import { matchesCategory } from './categories.js';
import { getMetric, getScreenshotUrl, getIconUrl } from './snapshot-loader.js';

const BROWSABLE_SORTS = new Set(['relevance', 'popularity', 'trend_7d', 'recent', 'updated', 'name']);

/**
 * Normalize and validate query state.
 * 
 * Supported query parameters:
 * - preset: 'discover', 'popular', 'rising', 'hot', 'new', 'all' (default: 'discover')
 * - search: free-text search string
 * - category: heuristic category key or 'github' (default: null)
 * - sort_by: 'relevance', 'popularity', 'recent', 'name', 'updated', 'trend_7d'
 * - time_range: 'last_1_month', 'last_2_months', 'last_6_months', 'last_year'
 * - date_field: 'created', 'updated' (default: 'updated')
 * - min_rating: numeric rating threshold
 * - min_downloads: numeric download threshold
 * - min_gnome_version: minimum supported GNOME version (this or higher)
 * Pagination is kept in the current UI session and is not part of shareable URLs.
 * 
 * @param {Object} query - Query parameters object
 * @param {number} pageSize - Items per page (from snapshot)
 * @returns {Object} Normalized filter state
 */
export function normalizeQueryState(query = {}, pageSize = 20) {
    const state = {
        preset: query.preset || 'discover',
        search: '',
        category: null,
        sortBy: 'popularity',
        timeRange: null,
        dateField: 'updated',
        minRating: null,
        minDownloads: null,
        minGnomeVersion: null,
        page: Math.max(1, parseInt(query.page || 1, 10)),
        pageSize,
    };

    // Apply preset defaults first.
    applyPreset(state);

    // Explicit query params override preset defaults.
    if (typeof query.search === 'string') {
        state.search = query.search;
    }
    if (typeof query.category === 'string' && query.category !== '') {
        state.category = query.category;
    }
    if (typeof query.sort_by === 'string' && BROWSABLE_SORTS.has(query.sort_by)) {
        state.sortBy = query.sort_by;
    } else if (state.search) {
        // Search defaults to the relevance score until a visitor chooses another sort.
        state.sortBy = 'relevance';
    }
    if (typeof query.time_range === 'string' && query.time_range !== '') {
        state.timeRange = query.time_range;
    }
    if (typeof query.date_field === 'string' && query.date_field !== '') {
        state.dateField = query.date_field;
    }
    if (query.min_rating !== undefined && query.min_rating !== null && query.min_rating !== '') {
        state.minRating = parseFloat(query.min_rating);
    }
    if (query.min_downloads !== undefined && query.min_downloads !== null && query.min_downloads !== '') {
        state.minDownloads = parseInt(query.min_downloads, 10);
    }
    if (query.min_gnome_version !== undefined && query.min_gnome_version !== null && query.min_gnome_version !== '') {
        state.minGnomeVersion = String(query.min_gnome_version);
    }

    return state;
}

/**
 * Apply preset filter settings.
 * 
 * @param {Object} state - Filter state object (modified in-place)
 */
function applyPreset(state) {
    switch (state.preset) {
        case 'discover':
            // Curated landing view assembled client-side; no preset overrides.
            break;

        case 'popular':
            state.sortBy = 'popularity';
            state.timeRange = null;
            state.dateField = 'updated';
            state.minDownloads = null;
            state.minRating = null;
            break;

        case 'rising':
            state.timeRange = 'last_1_month';
            state.dateField = 'created';
            state.minDownloads = null;
            state.minRating = null;
            state.sortBy = 'trend_1d';
            break;

        case 'hot':
            state.timeRange = null;
            state.dateField = 'updated';
            state.minDownloads = null;
            state.minRating = null;
            state.sortBy = 'trend_7d';
            break;

        case 'new':
            state.timeRange = 'last_1_month';
            state.dateField = 'created';
            state.sortBy = 'recent';
            state.minDownloads = null;
            state.minRating = null;
            break;

        case 'all':
            // The browsable index defaults to the source-neutral score.
            state.sortBy = 'popularity';
            break;

        default:
            // Unknown presets keep the base sort; URL params still override.
            break;
    }
}

/**
 * Filter and sort items based on filter state.
 * 
 * @param {Object[]} items - Array of extension items
 * @param {Object} filterState - Normalized filter state
 * @returns {Object} Filtered results with pagination
 */
export function applyFilters(items, filterState) {
    // Apply search filter
    let filtered = items;
    const searchTerms = filterState.search
        ? filterState.search.toLowerCase().split(/\s+/).filter(Boolean)
        : [];
    if (searchTerms.length > 0) {
        filtered = items.filter(item => {
            const searchText = `${item.name} ${item.description} ${item.creator}`.toLowerCase();
            return searchTerms.every(term => searchText.includes(term));
        });
    }

    // Apply category filter (client-side heuristic categories)
    if (filterState.category) {
        filtered = filtered.filter((item) => matchesCategory(item, filterState.category));
    }

    // Apply min rating filter (ratings only exist on the EGO source)
    if (filterState.minRating !== null) {
        filtered = filtered.filter(item => (getMetric(item, 'ego', 'rating') ?? 0) >= filterState.minRating);
    }

    // Apply min downloads filter (EGO is the only source with download counts)
    if (filterState.minDownloads !== null) {
        filtered = filtered.filter(item => (getMetric(item, 'ego', 'downloads') ?? 0) >= filterState.minDownloads);
    }

    if (filterState.minGnomeVersion !== null) {
        filtered = filtered.filter((item) => {
            const highestVersion = getHighestSupportedShellVersion(item.supportedShellVersions);
            if (highestVersion === null) {
                return false;
            }

            return compareShellVersions(highestVersion, filterState.minGnomeVersion) >= 0;
        });
    }

    if (filterState.timeRange) {
        const nowMs = Date.now();
        const cutoffMs = resolveCutoffMs(filterState.timeRange, nowMs);

        if (cutoffMs !== null) {
            filtered = filtered.filter((item) => {
                const dateValue = filterState.dateField === 'created'
                    ? item.createdAt
                    : item.updatedAt;

                const itemMs = Date.parse(dateValue);
                if (Number.isNaN(itemMs)) {
                    return false;
                }

                return itemMs >= cutoffMs;
            });
        }
    }

    // Apply sorting
    let sorted = sortItems(filtered, filterState.sortBy);

    if (searchTerms.length > 0 && filterState.sortBy === 'relevance') {
        // Alternative sorts must not be overridden by relevance ranking.
        sorted = [...sorted].sort((a, b) => compareSearchRelevance(searchRank(a, searchTerms), searchRank(b, searchTerms)));
    }

    // Apply pagination
    const totalCount = sorted.length;
    const totalPages = Math.ceil(totalCount / filterState.pageSize);
    const currentPage = Math.min(filterState.page, Math.max(1, totalPages));
    const offset = (currentPage - 1) * filterState.pageSize;
    const pageItems = sorted.slice(offset, offset + filterState.pageSize);

    return {
        items: pageItems,
        totalCount,
        totalPages,
        currentPage,
        pageSize: filterState.pageSize,
    };
}

/* ------------------------------------------------- Search relevance */

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// Unicode-aware boundaries keep non-ASCII extension names searchable.
function isWholeWordMatch(haystack, term) {
    const boundary = '[^\\p{L}\\p{N}_]';
    return new RegExp(`(?:^|${boundary})${escapeRegExp(term)}(?:$|${boundary})`, 'u').test(haystack);
}

function descriptionRelevance(index, length) {
    return 1 - index / length;
}

// The vector identifies the matching field before quality is blended in.
function termRelevanceVector(item, term) {
    const name = (item.name ?? '').toLowerCase();
    if (name.includes(term)) {
        return isWholeWordMatch(name, term) ? [1, 0, 0, 0] : [0, 1, 0, 0];
    }

    const description = (item.description ?? '').toLowerCase();
    const descriptionIndex = description.indexOf(term);
    if (descriptionIndex !== -1) {
        return [0, 0, descriptionRelevance(descriptionIndex, description.length), 0];
    }

    const creator = (item.creator ?? '').toLowerCase();
    return creator.includes(term) ? [0, 0, 0, 1] : [0, 0, 0, 0];
}

export function searchRelevance(item, terms) {
    return terms.reduce(
        (totals, term) => totals.map((value, index) => value + termRelevanceVector(item, term)[index]),
        [0, 0, 0, 0],
    );
}

// Ratings, reviews, stars and available media distinguish established extensions.
export function qualityBoost(item) {
    let boost = 0;

    const rating = getMetric(item, 'ego', 'rating');
    if (typeof rating === 'number' && rating > 0) boost += rating * 4;

    const reviews = getMetric(item, 'ego', 'comments');
    if (typeof reviews === 'number' && reviews > 0) boost += Math.min(reviews, 50) * 0.2;

    const stars = getMetric(item, 'github', 'stars');
    if (typeof stars === 'number' && stars > 0) boost += Math.min(stars, 1000) / 50;

    if (getScreenshotUrl(item)) boost += 4;
    if (getIconUrl(item)) boost += 2;

    return boost;
}

// Text remains the primary signal; normalized score and source trust break weak matches.
const SEARCH_TEXT_WEIGHTS = { nameWholeWord: 100, nameSubstring: 85, description: 80, otherField: 20 };
const SEARCH_SCORE_WEIGHT = 0.4;
const SEARCH_QUALITY_WEIGHT = 0.5;

export function searchRank(item, terms) {
    const [nameWholeWord, nameSubstring, description, otherField] = searchRelevance(item, terms);
    // Title matches remain strongest, but proven quality can outrank weak title-only matches.
    const textScore = nameWholeWord * SEARCH_TEXT_WEIGHTS.nameWholeWord
        + nameSubstring * SEARCH_TEXT_WEIGHTS.nameSubstring
        + description * SEARCH_TEXT_WEIGHTS.description
        + otherField * SEARCH_TEXT_WEIGHTS.otherField;
    const qualityScore = (item.score ?? 0) * SEARCH_SCORE_WEIGHT
        + qualityBoost(item) * SEARCH_QUALITY_WEIGHT;

    return [textScore + qualityScore, item.score ?? 0];
}

function compareSearchRelevance(a, b) {
    for (let index = 0; index < a.length; index++) {
        if (a[index] !== b[index]) return b[index] - a[index];
    }
    return 0;
}

/* ------------------------------------------------- Compatibility gate */

/** Main GNOME releases: modern majors plus the historical GNOME 3 series. */
const MAIN_SHELL_VERSION = /^(?:\d+|3\.\d+)$/;

/**
 * Selectable shell versions include modern majors and historical GNOME 3
 * series, while point releases such as "50.1" stay out of the menu.
 *
 * @param {Object[]} items - Snapshot items
 * @returns {string[]} Shell versions, newest first
 */
export function collectShellVersionOptions(items) {
    const versions = new Set();

    for (const item of items ?? []) {
        for (const raw of item.supportedShellVersions ?? []) {
            const value = String(raw).trim();
            if (MAIN_SHELL_VERSION.test(value)) {
                versions.add(value);
            }
        }
    }

    return Array.from(versions).sort(compareShellVersionsDescending);
}

/**
 * Newest-first ordering across mixed depths for internal compatibility data.
 */
function compareShellVersionsDescending(a, b) {
    const numeric = compareShellVersions(b, a);
    if (numeric !== 0) {
        return numeric;
    }

    return b.split('.').length - a.split('.').length;
}

/**
 * Explicit compatibility: the item declares exactly this shell version.
 * Neither ranges nor minimum versions apply — an item declaring "46" is not
 * compatible with a "46.2" selection and vice versa. A missing or empty
 * declaration is never treated as compatible.
 *
 * @param {Object} item - Snapshot item
 * @param {string} shellVersion - Declared shell version, e.g. "50" or "3.36"
 * @returns {boolean}
 */
export function supportsShellVersion(item, shellVersion) {
    return (item.supportedShellVersions ?? []).some((raw) => String(raw).trim() === shellVersion);
}

/**
 * Global compatibility gate applied before every other filter, so Discover
 * hero, Discover sections, sidebar counts and all list views agree on the
 * same visible pool. `null` means "Any GNOME version" and passes everything,
 * including items without any declared compatibility.
 *
 * @param {Object[]} items - Snapshot items
 * @param {string|null} shellVersion - Selected shell version or null
 * @returns {Object[]} Items compatible with the selection
 */
export function filterByShellVersion(items, shellVersion) {
    if (shellVersion === null) {
        return items;
    }

    return items.filter((item) => supportsShellVersion(item, shellVersion));
}

/**
 * Validate a (possibly stale or hand-edited) shell version against the
 * versions the current snapshot actually offers. Anything unknown falls
 * back to "Any GNOME version".
 *
 * @param {*} value - Candidate shell version
 * @param {string[]} options - Result of collectShellVersionOptions()
 * @returns {string|null} A selectable version or null
 */
export function normalizeShellVersion(value, options) {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim();

    return options.includes(normalized) ? normalized : null;
}

function resolveCutoffMs(timeRange, nowMs) {
    const dayMs = 24 * 60 * 60 * 1000;

    switch (timeRange) {
        case 'last_1_month':
            return nowMs - (30 * dayMs);
        case 'last_2_months':
            return nowMs - (60 * dayMs);
        case 'last_6_months':
            return nowMs - (183 * dayMs);
        case 'last_year':
            return nowMs - (365 * dayMs);
        default:
            return null;
    }
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

/**
 * Sort items by the specified field.
 * 
 * @param {Object[]} items - Items to sort
 * @param {string} sortBy - Sort field: 'popularity', 'recent', 'name', 'updated', 'trend_1d', 'trend_7d'
 * @returns {Object[]} Sorted items
 */
function sortItems(items, sortBy) {
    const sorted = [...items];

    switch (sortBy) {
        case 'recent':
            sorted.sort((a, b) => b.recentSortValue - a.recentSortValue);
            break;

        case 'updated':
            sorted.sort((a, b) => updatedTimeMs(b) - updatedTimeMs(a));
            break;

        case 'name':
            sorted.sort((a, b) => a.name.localeCompare(b.name));
            break;

        case 'trend_7d':
            // Real trending: item.trendScore is the source-neutral, batch-
            // normalized 7-day rank computed by the backend (0 = not
            // trend-eligible). Tie-break on the best raw 7-day delta across
            // sources, then recency.
            sorted.sort((a, b) => {
                const scoreDiff = (b.trendScore ?? 0) - (a.trendScore ?? 0);
                if (scoreDiff !== 0) return scoreDiff;

                const bDelta = bestSourceDelta(b, 'downloadsDelta7d', 'starsDelta7d');
                const aDelta = bestSourceDelta(a, 'downloadsDelta7d', 'starsDelta7d');

                // Only apply delta tie-break if at least one has a measured delta
                if (bDelta !== null || aDelta !== null) {
                    // Item with delta ranks higher than item without delta
                    if (bDelta === null) return -1;
                    if (aDelta === null) return 1;
                    const deltaDiff = bDelta - aDelta;
                    if (deltaDiff !== 0) return deltaDiff;
                }

                return b.recentSortValue - a.recentSortValue;
            });
            break;

        case 'trend_1d':
            // No normalized 1-day score exists (trendScore is 7d-based by
            // contract); rank by the best raw 1-day delta across sources,
            // then fall back to the 7d trendScore, then recency.
            sorted.sort((a, b) => {
                const bDelta = bestSourceDelta(b, 'downloadsDelta1d', 'starsDelta1d');
                const aDelta = bestSourceDelta(a, 'downloadsDelta1d', 'starsDelta1d');

                // Only apply delta tie-break if at least one has a measured delta
                if (bDelta !== null || aDelta !== null) {
                    // Item with delta ranks higher than item without delta
                    if (bDelta === null) return -1;
                    if (aDelta === null) return 1;
                    const deltaDiff = bDelta - aDelta;
                    if (deltaDiff !== 0) return deltaDiff;
                }

                const scoreDiff = (b.trendScore ?? 0) - (a.trendScore ?? 0);
                if (scoreDiff !== 0) return scoreDiff;

                return b.recentSortValue - a.recentSortValue;
            });
            break;

        case 'popularity':
        default:
            sorted.sort((a, b) => b.score - a.score);
            break;
    }

    return sorted;
}

function updatedTimeMs(item) {
    return Date.parse(item.updatedAt) || 0;
}

/**
 * Best (max) delta value across an item's sources for a given trend
 * window, reading the EGO metric key from 'ego' sources and the GitHub
 * metric key from 'github' sources. EGO downloads and GitHub stars are
 * different units; the raw max is only a local ordering hint and must not
 * be read as a cross-source primary product score.
 *
 * @param {Object} item - Snapshot v2 item
 * @param {string} egoKey - e.g. 'downloadsDelta7d'
 * @param {string} githubKey - e.g. 'starsDelta7d'
 * @returns {number|null} the best available delta, or null when none is measured
 */
function bestSourceDelta(item, egoKey, githubKey) {
    let best = null;

    for (const source of item.sources ?? []) {
        const key = source.sourceType === 'ego' ? egoKey : (source.sourceType === 'github' ? githubKey : null);
        const value = key ? source.metrics?.[key] : undefined;
        if (typeof value === 'number' && (best === null || value > best)) {
            best = value;
        }
    }

    return best;
}
