/**
 * Shell Configuration Module
 * 
 * Reads the app mount element and feed URL from the DOM.
 * Validates configuration on boot.
 */

export class BootConfigError extends Error {
    constructor(message) {
        super(message);
        this.name = 'BOOT_CONFIG_ERROR';
    }
}

/**
 * Read shell configuration from the app mount element.
 * 
 * @param {Document} document - The DOM document
 * @returns {Object} Configuration object with mountElement, feedUrl, etc.
 * @throws {BootConfigError} if configuration is invalid
 */
export function readShellConfig(document) {
    const mountElement = document.querySelector('#app');
    if (!mountElement) {
        throw new BootConfigError('Mount element #app not found in DOM');
    }

    const feedUrl = mountElement.dataset.feedUrl;
    if (!feedUrl) {
        throw new BootConfigError('data-feed-url attribute missing from mount element');
    }

    return {
        mountElement,
        feedUrl,
        basePath: '/',
    };
}
