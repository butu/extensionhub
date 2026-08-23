/**
 * Open/close state of the header search that CSS collapses below 760px.
 */

const COLLAPSED_QUERY = '(max-width: 759px)';
const OPEN_CLASS = 'is-open';

export function initHeaderSearchToggle(document, window) {
    const search = document.querySelector('.eh-header-search');
    const input = search?.querySelector('#search-input');

    if (!search || !input || search.dataset.ehSearchToggle === '1') {
        return;
    }

    search.dataset.ehSearchToggle = '1';

    const media = window.matchMedia(COLLAPSED_QUERY);
    const isOpen = () => search.classList.contains(OPEN_CLASS);

    const open = () => {
        search.classList.add(OPEN_CLASS);
        input.focus();
    };

    const close = () => {
        search.classList.remove(OPEN_CLASS);
    };

    // The collapsed input is zero-width, so the pill itself takes the tap.
    search.addEventListener('click', () => {
        if (media.matches && !isOpen()) {
            open();
        }
    });

    // Ctrl/K and the "clear filters" action focus the input directly; the
    // field has to be visible before it can receive that focus.
    input.addEventListener('focus', () => {
        if (media.matches) {
            search.classList.add(OPEN_CLASS);
        }
    });

    // An empty field carries no state worth keeping expanded; a search term
    // stays visible so the user can see and edit what is filtering the list.
    input.addEventListener('blur', () => {
        if (input.value.trim() === '') {
            close();
        }
    });

    input.addEventListener('keydown', (event) => {
        if (media.matches && event.key === 'Escape') {
            input.blur();
            close();
        }
    });

    media.addEventListener('change', (event) => {
        if (!event.matches) {
            close();
        }
    });
}
