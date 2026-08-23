/**
 * Router State Module
 * 
 * Parses the current URL path/query and derives app state.
 * Manages browser history for navigation.
 */

/**
 * Parse the current router state from location.
 *
 * Supports:
 * - List view: '/'
 * - Detail view: '/extension/{uuid}' (uuid raw or URL-encoded, e.g. 'drive-menu@x' / 'drive-menu%40x')
 * - Query parameters: '?preset=hot&search=dashboard'
 *
 * @param {Location} location - The browser location object
 * @returns {Object} Router state object
 */
export function parseRouterState(location) {
    const pathname = location.pathname;
    const searchParams = new URLSearchParams(location.search);

    // Detect if we're on a detail page
    const detailMatch = pathname.match(/^\/extension\/([^/]+)/);

    if (detailMatch) {
        // Detail view; the uuid may arrive percent-encoded or raw
        let uuid = detailMatch[1];
        try {
            uuid = decodeURIComponent(uuid);
        } catch {
            // Malformed escape sequence: keep the raw segment
        }

        return {
            view: 'detail',
            uuid,
            query: Object.fromEntries(searchParams),
            pathname,
        };
    }

    // List view (default)
    return {
        view: 'list',
        query: Object.fromEntries(searchParams),
        pathname,
    };
}

/**
 * Push a new router state to history.
 * 
 * @param {string} pathname - New pathname
 * @param {Object} query - Query parameters object
 * @param {string} title - Document title
 */
export function pushRouterState(pathname, query = {}, title = '') {
    const queryString = new URLSearchParams(query).toString();
    const url = queryString ? `${pathname}?${queryString}` : pathname;
    
    window.history.pushState({ view: 'app' }, title, url);
    if (title) {
        document.title = title;
    }
}

/**
 * Replace the current router state in history.
 * 
 * @param {string} pathname - New pathname
 * @param {Object} query - Query parameters object
 * @param {string} title - Document title
 */
export function replaceRouterState(pathname, query = {}, title = '') {
    const queryString = new URLSearchParams(query).toString();
    const url = queryString ? `${pathname}?${queryString}` : pathname;
    
    window.history.replaceState({ view: 'app' }, title, url);
    if (title) {
        document.title = title;
    }
}
