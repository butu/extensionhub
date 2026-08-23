/**
 * Comments Loader Module
 *
 * Fetches and caches the comments snapshot.
 * Loaded lazily when the user opens a detail view.
 */

let cachedComments = null;
let loadingPromise = null;

/**
 * Load the comments snapshot from the given URL.
 * Returns the cached result on subsequent calls.
 *
 * The comments snapshot stays at schemaVersion 1, but its `comments`
 * map is grouped by extension UUID (snapshot v2 identity).
 *
 * @param {string} commentsUrl - URL to fetch the comments snapshot from
 * @returns {Promise<Object>} The comments payload (keyed by extension UUID)
 */
export async function loadComments(commentsUrl) {
    // Return cached data if available
    if (cachedComments !== null) {
        return cachedComments;
    }

    // Deduplicate concurrent requests
    if (loadingPromise !== null) {
        return loadingPromise;
    }

    loadingPromise = fetchAndValidate(commentsUrl);

    try {
        cachedComments = await loadingPromise;
        return cachedComments;
    } finally {
        loadingPromise = null;
    }
}

/**
 * Get comments for a specific extension UUID from the cached data.
 * Returns an empty array if no comments exist or data is not loaded yet.
 *
 * @param {string} uuid - Extension UUID
 * @returns {Array} Array of comment objects
 */
export function getCommentsForExtension(uuid) {
    if (cachedComments === null) {
        return [];
    }

    return cachedComments[String(uuid)] ?? [];
}

/**
 * Derive the comments URL from the extensions feed URL.
 * Replaces the filename in the feed URL path.
 *
 * @param {string} feedUrl - The extensions feed URL (e.g. /data/extensions.json)
 * @returns {string} The comments URL (e.g. /data/comments.json)
 */
export function deriveCommentsUrl(feedUrl) {
    const lastSlash = feedUrl.lastIndexOf('/');
    if (lastSlash === -1) {
        return 'comments.json';
    }

    return feedUrl.substring(0, lastSlash + 1) + 'comments.json';
}

/**
 * Fetch and validate the comments snapshot.
 *
 * @param {string} url - URL to fetch
 * @returns {Promise<Object>} Comments keyed by extension UUID
 */
async function fetchAndValidate(url) {
    try {
        const response = await fetch(url);
        if (!response.ok) {
            console.error(`Failed to load comments: HTTP ${response.status}`);
            return {};
        }

        const payload = await response.json();

        // Basic validation
        if (payload.schemaVersion !== 1 || typeof payload.comments !== 'object') {
            console.error('Invalid comments snapshot schema');
            return {};
        }

        return payload.comments;
    } catch (error) {
        console.error('Failed to load comments:', error);
        return {};
    }
}
