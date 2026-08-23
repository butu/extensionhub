import './app.css';
import '../node_modules/glightbox/dist/css/glightbox.min.css';

import GLightbox from 'glightbox';
import { initializeApp } from './app/ui-state.js';
import { initHeaderSearchToggle } from './app/mobile-search.js';

// Initialize the lightbox for screenshots
const lightbox = GLightbox({
    selector: '.glightbox',
    touchNavigation: true,
    loop: true,
    keyboardNavigation: true,
    autoplayVideos: true
});

window.__ehLightbox = lightbox;

// Register the service worker so the app shell can be installed and works offline.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((error) => {
            console.error('Service worker registration failed:', error);
        });
    });
}

// Initialize the extensions browser app
document.addEventListener('DOMContentLoaded', async () => {
    initHeaderSearchToggle(document, window);

    try {
        await initializeApp(document, window);
    } catch (error) {
        console.error('Failed to initialize app:', error);
    }
});

// Handle browser back/forward
window.addEventListener('popstate', async () => {
    // Re-initialize or update the UI based on new location
    // This is handled by initializeApp reacting to location changes
    try {
        await initializeApp(document, window);
    } catch (error) {
        console.error('Failed to handle navigation:', error);
    }
});

window.addEventListener('keydown', async (event) => {
    const isShortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';
    if (!isShortcut) {
        return;
    }

    event.preventDefault();

    const focusSearchInput = () => {
        const searchInput = document.querySelector('#search-input');
        if (!searchInput) {
            return false;
        }

        searchInput.focus();
        const valueLength = searchInput.value?.length ?? 0;
        searchInput.setSelectionRange(valueLength, valueLength);
        return true;
    };

    if (focusSearchInput()) {
        return;
    }

    if (window.location.pathname !== '/') {
        window.history.pushState({ view: 'app' }, '', '/');

        try {
            await initializeApp(document, window);
        } catch (error) {
            console.error('Failed to handle Ctrl+K navigation:', error);
            return;
        }

        focusSearchInput();
    }
});
