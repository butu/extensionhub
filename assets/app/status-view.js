/**
 * Status View Module
 * 
 * Renders loading, error, empty, and not-found states.
 * Provides fallback UI while the main app loads or encounters errors.
 */

/**
 * Render a loading state.
 * 
 * @param {HTMLElement} container - Element to render into
 */
export function renderLoadingState(container) {
    container.innerHTML = `
        <div class="eh-panel flex flex-col items-center justify-center py-16 gap-4">
            <div class="loading loading-spinner loading-lg text-primary"></div>
            <p class="text-base-content/65">Loading extensions...</p>
        </div>
    `;
}

/**
 * Render a boot error state (e.g., mount element not found).
 * 
 * @param {HTMLElement} container - Element to render into
 * @param {Error} error - The error that occurred
 */
export function renderBootErrorState(container, error) {
    container.innerHTML = `
        <div class="eh-panel alert alert-error max-w-xl mx-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l-2-2m0 0l-2-2m2 2l2-2m-2 2l-2 2m2-2l2 2m5-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <div>
                <h3 class="font-bold">Configuration Error</h3>
                <div class="text-sm">${escapeHtml(error.message)}</div>
                <div class="text-xs mt-2 opacity-75">The app shell is missing required configuration.</div>
            </div>
        </div>
    `;
}

/**
 * Render a load error state (e.g., network error, invalid snapshot).
 * 
 * @param {HTMLElement} container - Element to render into
 * @param {Error} error - The error that occurred
 * @param {Function} onRetry - Callback when user clicks retry
 */
export function renderLoadErrorState(container, error, onRetry) {
    container.innerHTML = `
        <div class="eh-panel alert alert-warning max-w-xl mx-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 4v2M6.343 6.343l1.414 1.414M12 6.343v1.414m3.243-3.243l1.414-1.414M18.364 6.343l-1.414 1.414" />
            </svg>
            <div>
                <h3 class="font-bold">Could Not Load Extensions</h3>
                <div class="text-sm">${escapeHtml(error.message)}</div>
                <button id="retry-btn" class="btn btn-sm btn-primary mt-3">Retry</button>
            </div>
        </div>
    `;
    document.getElementById('retry-btn').addEventListener('click', onRetry);
}

/**
 * Render an empty results state.
 * 
 * @param {HTMLElement} container - Element to render into
 * @param {Function} onClearFilters - Callback when user clears filters
 */
export function renderEmptyState(container, onClearFilters) {
    container.innerHTML = `
        <div class="eh-panel flex flex-col items-center justify-center py-16 gap-4">
            <div class="w-14 h-14 rounded-full bg-base-200 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-base-content/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <div class="text-center">
                <p class="text-base-content/60 text-sm mb-3">No extensions found</p>
                <button id="clear-filters-btn" class="btn btn-sm btn-primary rounded-lg">Clear filters</button>
            </div>
        </div>
    `;
    if (onClearFilters) {
        document.getElementById('clear-filters-btn').addEventListener('click', onClearFilters);
    }
}

/**
 * Render a not-found state (extension detail view with unknown UUID).
 *
 * @param {HTMLElement} container - Element to render into
 * @param {string} identifier - The identifier that was not found
 * @param {Function} onGoHome - Callback to navigate home
 */
export function renderNotFoundState(container, identifier, onGoHome) {
    container.innerHTML = `
        <div class="eh-panel flex flex-col items-center justify-center py-16 gap-4">
            <div class="w-14 h-14 rounded-full bg-base-200 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-base-content/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="text-center">
                <p class="text-base-content/60 text-sm mb-3">Extension not found</p>
                <button id="go-home-btn" class="btn btn-sm btn-primary rounded-lg">Browse all extensions</button>
            </div>
        </div>
    `;
    if (onGoHome) {
        document.getElementById('go-home-btn').addEventListener('click', onGoHome);
    }
}

/**
 * Render a stale data warning banner.
 * 
 * @param {HTMLElement} container - Element to render into (before main content)
 * @param {Function} onDismiss - Callback when user dismisses the warning
 */
export function renderStaleDataBanner(container, onDismiss) {
    const banner = document.createElement('div');
    banner.className = 'alert alert-warning mb-4 eh-panel';
    banner.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span>The extension data could not be refreshed. Showing cached results.</span>
        <button id="dismiss-banner-btn" class="btn btn-sm btn-ghost">Dismiss</button>
    `;
    container.insertBefore(banner, container.firstChild);
    
    document.getElementById('dismiss-banner-btn').addEventListener('click', () => {
        banner.remove();
        if (onDismiss) onDismiss();
    });
}

/**
 * Utility: Escape HTML special characters to prevent injection.
 * 
 * @param {string} text - Text to escape
 * @returns {string} Escaped text
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
