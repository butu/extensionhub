/**
 * List View Module
 *
 * Renders the shell layout (sidebar card + content column), the Discover
 * landing (trending hero slider, curated row sections, popular slider,
 * category tiles) and the list views (trending/popular/new/all/category/
 * search) including the sort dropdown and extension rows.
 */

import { applyFilters, normalizeQueryState } from './filter-engine.js';
import { CATEGORY_DEFS, categoryLabel, countCategory } from './categories.js';
import {
    getSource,
    getMetric,
    getPrimaryAction,
    getDetailPath,
    getIconUrl,
    getScreenshotUrl,
} from './snapshot-loader.js';

const SORT_OPTIONS = [
    { key: 'popularity', label: 'Most popular' },
    { key: 'trend_7d', label: 'Trending' },
    { key: 'recent', label: 'Newest' },
    { key: 'updated', label: 'Recently updated' },
    { key: 'name', label: 'Name A–Z' },
];

const SEARCH_SORT_OPTION = { key: 'relevance', label: 'Most relevant' };

const VIEW_META = {
    hot: { title: 'Trending now', note: 'Extensions gaining attention fastest this week.' },
    popular: { title: 'Popular', note: 'The highest-ranked GNOME Shell extensions.' },
    new: { title: 'New', note: 'Recently published extensions.' },
    all: { title: 'All extensions', note: 'Every GNOME Shell extension in the index.' },
};

// Sentinel for the unfiltered compatibility entry; dataset values are
// strings, so "any" stands in for the null selection.
const ANY_SHELL_OPTION = 'any';

// The mobile layout: the range in which CSS shows the chip strip instead of
// the sidebar. Behaviour that only belongs to that layout is gated on it.
const MOBILE_NAV_QUERY = '(max-width: 1023px)';

// Fixed palette for generic-icon letter tiles, stable per uuid hash.
const LETTER_TILE_PALETTE = ['#613583', '#1c71d8', '#2ec27e', '#c64600', '#986a44', '#e5a50a', '#a51d2d'];

const ICONS = {
    compass: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>',
    flame: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
    trophy: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
    sparkles: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg>',
    grid: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>',
    chevronDown: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
    check: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    arrowRight: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>',
    sliders: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M1 14h6"/><path d="M9 8h6"/><path d="M17 16h6"/></svg>',
    github: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>',
    // Simplified tanuki silhouette; reads as the GitLab mark at glyph size.
    gitlab: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22 8.6 15.5H2.5l2.9-8.9 3 4.7L12 3.2l3.6 8.1 3-4.7 2.9 8.9h-6.1L12 22Z"/></svg>',
    globe: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
    star: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.967a1 1 0 0 0 .95.69h4.175c.969 0 1.371 1.24.588 1.81l-3.38 2.455a1 1 0 0 0-.364 1.118l1.287 3.966c.3.922-.755 1.688-1.54 1.118l-3.38-2.454a1 1 0 0 0-1.175 0l-3.38 2.454c-.784.57-1.838-.196-1.54-1.118l1.287-3.966a1 1 0 0 0-.364-1.118L2.05 9.394c-.783-.783-.38-1.81.588-1.81h4.175a1 1 0 0 0 .95-.69l1.286-3.967z"/></svg>',
    external: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>',
    seal: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path fill="#33d17a" d="M12 .4 14.87 3.16 18.82 2.62 19.52 6.53 23.03 8.42 21.3 12 23.03 15.58 19.52 17.47 18.82 21.38 14.87 20.84 12 23.6 9.13 20.84 5.18 21.38 4.48 17.47 .97 15.58 2.7 12 .97 8.42 4.48 6.53 5.18 2.62 9.13 3.16Z"/><path d="M7.1 12.4l4.2 4.2 8.3-8.3" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
};

const CATEGORY_TILE_ICONS = {
    'top-bar': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/></svg>',
    launchers: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
    windows: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>',
    appearance: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
    system: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>',
    productivity: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.5"/><path d="m9 11 3 3L22 4"/></svg>',
    media: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/></svg>',
    devices: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/></svg>',
};

export function renderListView(container, state, callbacks = {}) {
    const { items, filterState, pagination, snapshot } = state;
    const compatibility = state.compatibility ?? { selected: null, options: [] };
    const allItems = snapshot?.items || [];
    const currentGnomeMajor = findCurrentGnomeMajor(allItems);
    const newCount = applyFilters(allItems, normalizeQueryState({ preset: 'new' }, snapshot.pageSize)).totalCount;
    const githubCount = countGitHubSources(allItems);
    const egoCount = countEgoSources(allItems);

    container.innerHTML = `
        <div class="eh-layout">
            ${renderMobileNav(filterState, { newCount }, compatibility)}
            ${renderSidebar(filterState, allItems, snapshot, { newCount, githubCount, egoCount }, compatibility)}
            <div class="eh-content">
                ${isDiscoverView(filterState)
                    ? renderDiscover(allItems, currentGnomeMajor)
                    : `<div class="eh-list-view">
                           ${renderListHeader(filterState, pagination)}
                           <div id="extensions-list" class="eh-list">
                               ${items.length > 0 ? items.map((item) => renderExtensionRow(item, currentGnomeMajor)).join('') : renderInlineEmptyState()}
                           </div>
                           ${pagination.currentPage < pagination.totalPages
                               ? `<div class="eh-load-more-wrap">
                                       <button type="button" id="load-more" class="eh-load-more">Load ${pagination.pageSize} more</button>
                                   </div>`
                               : ''}
                       </div>`}
            </div>
        </div>
    `;

    bindInteractions(container, callbacks, filterState, pagination);

    if (state.ui?.focusSearch) {
        const searchInput = document.querySelector('#search-input');
        searchInput?.focus();
        const pos = Math.min(state.ui.cursorPos ?? searchInput.value.length, searchInput.value.length);
        searchInput.setSelectionRange(pos, pos);
    }
}

/**
 * Discover is the preset-less landing: no search term, no category, no
 * explicit list preset. Everything else renders as a list view.
 */
function isDiscoverView(filterState) {
    return !filterState.search
        && !filterState.category
        && filterState.preset === 'discover';
}

/* ---------------------------------------------------------- Navigation */

// Explore presets in the binding sidebar order; shared by the sidebar rows
// and the mobile chip strip so both can never drift apart.
const EXPLORE_PRESETS = [
    { key: 'discover', label: 'Discover', icon: ICONS.compass },
    { key: 'hot', label: 'Trending', icon: ICONS.flame },
    { key: 'popular', label: 'Popular', icon: ICONS.trophy },
    { key: 'new', label: 'New', icon: ICONS.sparkles },
    { key: 'all', label: 'All extensions', icon: ICONS.grid },
];

/**
 * Mobile navigation: the sidebar's Explore presets, every category and the
 * compatibility filter as one horizontally scrollable pill row under the
 * header. CSS shows it below 1024px, where the sidebar card would otherwise
 * push the whole page content off the first screen.
 *
 * It reuses the `data-nav-preset` / `data-category` hooks, so the existing
 * interaction binding wires it up without a second code path.
 */
function renderMobileNav(filterState, counts, compatibility) {
    const presetChips = EXPLORE_PRESETS.map((preset) => `
        <button type="button" class="eh-chip ${filterState.preset === preset.key && !filterState.category && !filterState.search ? 'is-active' : ''}" data-nav-preset="${preset.key}">
            <span class="eh-chip-icon" aria-hidden="true">${preset.icon}</span>
            <span>${preset.label}</span>
            ${preset.key === 'new' ? `<span class="eh-chip-count">${formatCount(counts.newCount)}</span>` : ''}
        </button>
    `).join('');

    const categoryChips = CATEGORY_DEFS.map((def) => `
        <button type="button" class="eh-chip ${filterState.category === def.key ? 'is-active' : ''}" data-category="${def.key}">
            <span class="eh-chip-icon" aria-hidden="true">${CATEGORY_TILE_ICONS[def.key] ?? ICONS.grid}</span>
            <span>${def.label}</span>
        </button>
    `).join('');

    // Last chip of the same scroller, not a pinned trailing control: pinning
    // it permanently covered a third of the strip.
    const compatChip = renderCompatibilityPanel(compatibility, 'chip');

    return `
        <nav class="eh-mobilenav" aria-label="Browse extensions">
            <div class="eh-mobilenav-track" data-mobilenav-track>
                ${presetChips}
                ${categoryChips}
                ${compatChip}
            </div>
        </nav>
    `;
}

/* ------------------------------------------------------------- Sidebar */

function renderSidebar(filterState, items, snapshot, counts, compatibility) {
    const navExtras = {
        new: `<span class="eh-side-pill eh-side-pill-new">${formatCount(counts.newCount)}</span>`,
        all: `<span class="eh-side-pill eh-side-pill-all">${formatCount(items.length)}</span>`,
    };

    const navRows = EXPLORE_PRESETS.map((row) => `
        <button type="button" class="eh-nav-row ${filterState.preset === row.key ? 'is-active' : ''}" data-nav-preset="${row.key}">
            <span class="eh-nav-icon" aria-hidden="true">${row.icon}</span>
            <span class="eh-nav-label">${row.label}</span>
            ${navExtras[row.key] ?? ''}
        </button>
    `).join('');

    const categoryRows = CATEGORY_DEFS.map((def) => `
        <button type="button" class="eh-cat-row ${filterState.category === def.key ? 'is-active' : ''}" data-category="${def.key}">
            <span class="eh-cat-label">${def.label}</span>
            <span class="eh-cat-count">${formatCount(countCategory(items, def.key))}</span>
        </button>
    `).join('');

    // The Project group closes the sidebar: source counters (informational —
    // GitHub is a source, never a category) plus the colophon rows. It renders
    // here (not in base.html.twig) so the snapshot date always matches the
    // snapshot this sidebar was rendered from.
    const snapshotDate = formatSnapshotDate(snapshot.generatedAt);

    return `
        <aside class="eh-sidebar">
            <nav class="eh-side-group eh-side-group--explore" aria-label="Explore">
                <p class="eh-side-label eh-side-label--explore">Explore</p>
                ${navRows}
            </nav>
            <div class="eh-side-group eh-side-group--categories">
                <p class="eh-side-label">Categories</p>
                ${categoryRows}
            </div>
            ${renderCompatibilityPanel(compatibility, 'sidebar')}
            <div class="eh-side-group eh-side-group--project">
                <p class="eh-side-label">About Extension Hub</p>
                <div class="eh-source-row">
                    <span class="eh-source-name"><span class="eh-source-dot" aria-hidden="true"></span>extensions.gnome.org</span>
                    <span class="eh-cat-count">${formatCount(counts.egoCount)}</span>
                </div>
                <div class="eh-source-row">
                    <span class="eh-source-name"><span class="eh-source-mark" aria-hidden="true">${ICONS.github}</span>GitHub</span>
                    <span class="eh-cat-count">${formatCount(counts.githubCount)}</span>
                </div>
                <a class="eh-side-project-link" href="https://github.com/butu/extensionhub" target="_blank" rel="noopener noreferrer" aria-label="View Extension Hub source repository on GitHub">
                    <span class="eh-side-project-mark" aria-hidden="true">${ICONS.github}</span>
                    <span>Open source · MIT</span>
                    <span class="eh-side-project-ext" aria-hidden="true">${ICONS.external}</span>
                </a>
                ${snapshotDate ? `<p id="snapshot-generated-at" class="eh-side-project-note">Data snapshot: ${escapeHtml(snapshotDate)}</p>` : ''}
                <a class="eh-side-project-link" href="/use-the-data">About the data</a>
            </div>
        </aside>
    `;
}

/**
 * Compatibility panel: picks the GNOME Shell version the whole directory is
 * filtered against: "Any GNOME version" plus every numeric version declared
 * anywhere in the snapshot, newest first. Sits between Categories and the
 * closing "About Extension Hub" group like the reference layout; the selection
 * is persisted by the caller
 * (localStorage, never the URL). Renders nothing when the snapshot declares
 * no numeric versions at all.
 */
function renderCompatibilityPanel(compatibility, variant = 'sidebar') {
    const options = compatibility.options ?? [];
    if (options.length === 0) {
        return '';
    }

    const selected = compatibility.selected ?? null;
    const entries = [
        { value: ANY_SHELL_OPTION, label: 'Any GNOME version', active: selected === null },
        ...options.map((version) => ({
            value: version,
            label: `GNOME ${version}`,
            active: selected === version,
        })),
    ];

    const menu = `
        <div class="eh-compat-menu" role="listbox" aria-label="Filter by GNOME Shell version">
            ${entries.map((entry) => `
                <button type="button" class="eh-compat-option ${entry.active ? 'is-active' : ''}" role="option" aria-selected="${entry.active}" data-compat-option="${escapeHtmlAttr(entry.value)}">
                    ${escapeHtml(entry.label)}${ICONS.check}
                </button>
            `).join('')}
        </div>
    `;

    // Mobile variant: the same dropdown behind a trailing pill in the chip
    // strip, with a short label so it does not eat the scrollable row.
    if (variant === 'chip') {
        const chipLabel = selected === null ? 'Any GNOME' : `GNOME ${selected}`;

        return `
            <div class="eh-compat eh-compat--chip" data-compat-menu>
                <button type="button" class="eh-chip eh-chip--compat ${selected === null ? '' : 'is-active'}" data-compat-toggle aria-haspopup="listbox" aria-expanded="false" aria-label="Filter by GNOME Shell version">
                    <span class="eh-chip-icon" aria-hidden="true">${ICONS.sliders}</span>
                    <span>${escapeHtml(chipLabel)}</span>
                    <span class="eh-chip-icon eh-chip-chevron" aria-hidden="true">${ICONS.chevronDown}</span>
                </button>
                ${menu}
            </div>
        `;
    }

    const triggerLabel = selected === null ? 'Any GNOME version' : `Works with GNOME ${selected}`;

    return `
        <div class="eh-side-group eh-side-group--compat">
            <p class="eh-side-label">Compatibility</p>
            <div class="eh-compat" data-compat-menu>
                <button type="button" class="eh-compat-trigger" data-compat-toggle aria-haspopup="listbox" aria-expanded="false">
                    <span class="eh-compat-value">${escapeHtml(triggerLabel)}</span>
                    ${ICONS.chevronDown}
                </button>
                ${menu}
            </div>
        </div>
    `;
}

/* -------------------------------------------------------- Discover page */

function renderDiscover(allItems, currentGnomeMajor) {
    const trendingRanked = applyFilters(allItems, normalizeQueryState({ preset: 'hot' }, allItems.length || 1)).items;
    const newRanked = applyFilters(allItems, normalizeQueryState({ preset: 'new' }, allItems.length || 1)).items;
    const popularRanked = applyFilters(allItems, normalizeQueryState({ preset: 'popular' }, allItems.length || 1)).items;

    const heroPool = pickRandomSliderItems(trendingRanked.filter((item) => getScreenshotUrl(item)));
    const popularPool = pickRandomSliderItems(popularRanked.filter((item) => getScreenshotUrl(item)));
    const popularSlider = renderSlider(popularPool, 'popular', allItems, currentGnomeMajor);

    return `
        <div class="eh-discover">
            ${renderSlider(heroPool, 'trending', allItems, currentGnomeMajor)}
            ${renderSection('Trending now', 'Extensions gaining attention fastest this week.', 'hot', trendingRanked.slice(0, 5), currentGnomeMajor)}
            ${popularSlider ? `
                <section class="eh-sec eh-sec-popular">
                    ${renderSectionHead('Popular', 'The highest-ranked GNOME Shell extensions.', 'popular')}
                    ${popularSlider}
                </section>
            ` : ''}
            ${renderSection('New extensions', 'Recently published extensions.', 'new', newRanked.slice(1, 6), currentGnomeMajor)}
            ${renderCategoryTiles(allItems)}
        </div>
    `;
}

function renderSectionHead(title, note, seeAllPreset = null) {
    return `
        <div class="eh-sec-head">
            <div class="eh-sec-titles">
                <h2 class="eh-sec-title">${escapeHtml(title)}</h2>
                <p class="eh-sec-note">${escapeHtml(note)}</p>
            </div>
            ${seeAllPreset ? `<button type="button" class="eh-see-all" data-nav-preset="${seeAllPreset}">See all ${ICONS.arrowRight}</button>` : ''}
        </div>
    `;
}

function renderSection(title, note, seeAllPreset, items, currentGnomeMajor) {
    if (items.length === 0) {
        return '';
    }

    return `
        <section class="eh-sec">
            ${renderSectionHead(title, note, seeAllPreset)}
            <div class="eh-sec-items">
                ${items.map((item) => renderExtensionRow(item, currentGnomeMajor)).join('')}
            </div>
        </section>
    `;
}

function renderCategoryTiles(items) {
    return `
        <section class="eh-sec eh-sec-categories">
            <div class="eh-sec-head">
                <div class="eh-sec-titles">
                    <h2 class="eh-sec-title">Browse by category</h2>
                    <p class="eh-sec-note">Stable entry points into every corner of the index.</p>
                </div>
            </div>
            <div class="eh-cat-grid">
                ${CATEGORY_DEFS.map((def) => `
                    <button type="button" class="eh-cat-tile" data-category="${def.key}">
                        <span class="eh-cat-tile-icon" aria-hidden="true">${CATEGORY_TILE_ICONS[def.key] ?? ICONS.grid}</span>
                        <span class="eh-cat-tile-text">
                            <span class="eh-cat-tile-label">${def.label}</span>
                            <span class="eh-cat-tile-count">${formatCount(countCategory(items, def.key))} extensions</span>
                        </span>
                    </button>
                `).join('')}
            </div>
        </section>
    `;
}

/**
 * Blur-backed slider panel shared by the fresh-updates hero and the Popular
 * slider. Dots only (no arrows); single-item pools render statically.
 */
function renderSlider(pool, variant, allItems, currentGnomeMajor) {
    const items = pool.length > 0 ? pool : fallbackSliderPool(allItems, currentGnomeMajor, variant);
    if (items.length === 0) {
        return '';
    }

    return `
        <section class="eh-hero eh-hero--${variant} ${items.length > 1 ? 'eh-hero--sliding' : ''}" data-hero-slider aria-roledescription="carousel" aria-label="${variant === 'trending' ? 'Recently updated extensions' : 'Popular extensions'}">
            <div class="eh-hero-track">
                ${items.map((item, index) => renderHeroSlide(item, index, variant)).join('')}
            </div>
            ${items.length > 1 ? `
                <div class="eh-hero-dots" role="tablist" aria-label="${variant === 'trending' ? 'Recently updated extensions' : 'Popular extensions'}">
                    ${items.map((item, index) => `
                        <button type="button" class="eh-hero-dot ${index === 0 ? 'is-active' : ''}" role="tab" aria-selected="${index === 0}" aria-label="${escapeHtmlAttr(item.name)}" data-hero-dot="${index}"></button>
                    `).join('')}
                </div>
            ` : ''}
        </section>
    `;
}

function pickRandomSliderItems(items) {
    const candidates = items.slice(0, 15);

    for (let index = candidates.length - 1; index > 0; index -= 1) {
        const randomIndex = Math.floor(Math.random() * (index + 1));
        [candidates[index], candidates[randomIndex]] = [candidates[randomIndex], candidates[index]];
    }

    return candidates.slice(0, 5);
}

function fallbackSliderPool(allItems, currentGnomeMajor, variant) {
    const qualified = allItems.filter((item) => getScreenshotUrl(item) && isCompatible(item, currentGnomeMajor));
    return qualified.slice(0, 1);
}

function renderHeroSlide(item, index, variant) {
    const screenshot = resolveAssetUrl(getScreenshotUrl(item));
    const rating = Number(getMetric(item, 'ego', 'rating') ?? 0);
    const hasRating = getMetric(item, 'ego', 'rating') !== undefined && rating > 0;
    const githubStars = getMetric(item, 'github', 'stars');
    const starsClass = variant === 'trending' ? 'eh-stars--hero' : 'eh-stars--hero-pop';

    // GitHub-only items have neither rating nor downloads; show no review
    // line at all instead of a misleading "No reviews yet" label.
    let reviewLabel = null;
    if (hasRating) {
        const reviews = getMetric(item, 'ego', 'comments') ?? 0;
        reviewLabel = `${rating.toFixed(1)} rating · ${reviews} reviews`;
    } else if (variant === 'popular') {
        const downloads = getMetric(item, 'ego', 'downloads');
        if (downloads !== undefined) {
            reviewLabel = `${formatCompactCount(downloads)} downloads`;
        }
    } else if (getSource(item, 'ego')) {
        reviewLabel = 'No reviews yet';
    }

    return `
        <article class="eh-hero-slide ${index === 0 ? 'is-active' : ''}" data-hero-uuid="${escapeHtmlAttr(item.uuid)}" aria-hidden="${index !== 0}">
            <div class="eh-hero-blur" aria-hidden="true"><img src="${escapeHtmlAttr(screenshot)}" alt="" loading="lazy"></div>
            <div class="eh-hero-scrim" aria-hidden="true"></div>
            <a class="eh-hero-shot glightbox" href="${escapeHtmlAttr(screenshot)}" aria-label="Open ${escapeHtmlAttr(item.name)} screenshot">
                <img src="${escapeHtmlAttr(screenshot)}" alt="${escapeHtmlAttr(item.name)} screenshot" loading="lazy">
            </a>
            <div class="eh-hero-body">
                <${variant === 'trending' ? 'h1' : 'h3'} class="eh-hero-title">${escapeHtml(item.name)}</${variant === 'trending' ? 'h1' : 'h3'}>
                <p class="eh-hero-desc">${escapeHtml(item.description || '')}</p>
                ${reviewLabel !== null || githubStars !== undefined ? `
                    <div class="eh-hero-reviews">
                        ${reviewLabel !== null ? `
                            <span class="eh-stars ${starsClass}" role="img" aria-label="${hasRating ? `Rated ${rating.toFixed(1)} of 5` : 'No reviews yet'}">
                                <span class="eh-stars-dim" aria-hidden="true"></span>
                                ${hasRating ? `<span class="eh-stars-fill" style="width: ${(rating / 5) * 100}%" aria-hidden="true"></span>` : ''}
                            </span>
                            <span class="eh-hero-reviews-label">${escapeHtml(reviewLabel)}</span>
                        ` : ''}
                        ${githubStars !== undefined ? `<span class="eh-hero-github-stars" title="GitHub stars"><span class="eh-github-mark">${ICONS.github}</span><span class="eh-star-mark">${ICONS.star}</span><span>${formatCompactCount(githubStars)} stars</span></span>` : ''}
                    </div>
                ` : ''}
                <div>
                    <button type="button" class="eh-hero-cta" data-hero-detail="1">View extension</button>
                </div>
            </div>
        </article>
    `;
}

/* ------------------------------------------------------------ List view */

function renderListHeader(filterState, pagination) {
    const heading = getListHeading(filterState, pagination);

    return `
        <div class="eh-list-header">
            <div class="eh-list-heading">
                <h1 class="eh-list-title">${escapeHtml(heading.title)}</h1>
                <p class="eh-list-note">${escapeHtml(heading.note)}</p>
            </div>
            ${heading.showSort ? renderSortControl(filterState) : ''}
        </div>
    `;
}

function getListHeading(filterState, pagination) {
    if (filterState.search) {
        return {
            title: `Search “${filterState.search}”`,
            note: `${formatCount(pagination.totalCount)} extensions match your search.`,
            showSort: true,
        };
    }

    if (filterState.category) {
        return {
            title: categoryLabel(filterState.category),
            note: 'The highest-ranked extensions in this category.',
            showSort: true,
        };
    }

    const meta = VIEW_META[filterState.preset] ?? { title: 'Extensions', note: 'Browse GNOME Shell extensions.' };
    return { ...meta, showSort: filterState.preset === 'all' };
}

function renderSortControl(filterState) {
    const options = filterState.search ? [SEARCH_SORT_OPTION, ...SORT_OPTIONS] : SORT_OPTIONS;
    const active = options.find((option) => option.key === filterState.sortBy) ?? options[0];

    return `
        <div class="eh-sort" data-sort-menu>
            <button type="button" class="eh-sort-pill" data-sort-toggle aria-haspopup="listbox" aria-expanded="false">
                ${active.label} ${ICONS.chevronDown}
            </button>
            <div class="eh-sort-menu" role="listbox" aria-label="Sort extensions">
                ${options.map((option) => `
                    <button type="button" class="eh-sort-option ${option.key === active.key ? 'is-active' : ''}" role="option" aria-selected="${option.key === active.key}" data-sort-option="${option.key}">
                        ${option.label}${ICONS.check}
                    </button>
                `).join('')}
            </div>
        </div>
    `;
}

/* --------------------------------------------------------------- Rows */

function renderExtensionRow(item, currentGnomeMajor) {
    const detailPath = getDetailPath(item);
    const ratingValue = getMetric(item, 'ego', 'rating');
    const rating = Number(ratingValue ?? 0);
    const hasRating = ratingValue !== undefined && rating > 0;
    const hasEgoSource = getSource(item, 'ego') !== null;
    const githubStars = getMetric(item, 'github', 'stars');
    const action = getPrimaryAction(item);
    const actionIcon = action?.label === 'Repository' && getSource(item, 'github')
        ? `<span class="eh-install-icon" aria-hidden="true">${ICONS.github}</span>`
        : '';

    return `
        <article class="eh-row" data-card-uuid="${escapeHtmlAttr(item.uuid)}">
            <a class="eh-row-link" href="${escapeHtmlAttr(detailPath)}" data-detail-link="1" aria-label="View ${escapeHtmlAttr(item.name)}"></a>

            ${renderRowIcon(item)}

            <div class="eh-row-text">
                <h3 class="eh-row-name">
                    <span class="eh-row-name-text" title="${escapeHtmlAttr(item.name)}">${escapeHtml(item.name)}</span>
                    ${isOfficialGnomeSource(item) ? `<span class="eh-seal" title="Official GNOME project source">${ICONS.seal}</span>` : ''}
                    ${githubStars !== undefined ? `<span class="eh-row-github-stars" title="GitHub stars"><span class="eh-github-mark">${ICONS.github}</span><span class="eh-star-mark">${ICONS.star}</span><span>${formatCompactCount(githubStars)}</span></span>` : ''}
                </h3>
                <p class="eh-row-desc">${escapeHtml(item.description || '')}</p>
            </div>

            <div class="eh-row-metrics">
                <div class="eh-rating-slot">
                    ${hasRating
                        ? `<span class="eh-stars" role="img" aria-label="Rated ${rating.toFixed(1)} of 5">
                               <span class="eh-stars-dim" aria-hidden="true"></span>
                               <span class="eh-stars-fill" style="width: ${(rating / 5) * 100}%" aria-hidden="true"></span>
                           </span>
                           <span class="eh-rating-value">${rating.toFixed(1)}</span>
                           <span class="eh-rating-comments">(${getMetric(item, 'ego', 'comments') ?? 0})</span>`
                        // GitHub-only items have no EGO rating slot content at all.
                        : (hasEgoSource ? '<span class="eh-rating-none">No rating yet</span>' : '')}
                </div>
                <div class="eh-action-slot">
                    ${action
                        ? `<a class="eh-install" href="${escapeHtmlAttr(action.url)}" target="_blank" rel="noopener noreferrer" data-row-action="1">${actionIcon}${escapeHtml(action.label)}</a>`
                        : ''}
                </div>
            </div>

            ${getScreenshotUrl(item) ? renderPopover(item, true) : ''}
        </article>
    `;
}

function renderRowIcon(item) {
    const icon = resolveAssetUrl(getIconUrl(item));
    const isGeneric = !icon || icon.includes('/static/') || icon.includes('plugin.png');

    if (isGeneric) {
        const shot = resolveAssetUrl(getScreenshotUrl(item));
        const color = LETTER_TILE_PALETTE[stableTextHash(item.uuid ?? item.name ?? '') % LETTER_TILE_PALETTE.length];
        const letter = (item.name || '?').trim().charAt(0).toUpperCase();

        // Screenshot as blurred tile backdrop with the letter on top keeps the
        // per-extension color identity while still looking icon-less rows distinct.
        if (shot) {
            return `<span class="eh-row-icon eh-row-icon-shot" aria-hidden="true"><img src="${escapeHtmlAttr(shot)}" alt=""><span class="eh-row-icon-shot-letter">${escapeHtml(letter)}</span></span>`;
        }

        return `<span class="eh-row-icon eh-row-icon-letter" style="background-color: ${color}" aria-hidden="true">${escapeHtml(letter)}</span>`;
    }

    return `
        <span class="eh-row-icon" aria-hidden="true">
            <img src="${escapeHtmlAttr(icon)}" alt="" loading="lazy">
        </span>
    `;
}

/** Deterministic palette index; v2 has no numeric pk to index by anymore. */
function stableTextHash(text) {
    let hash = 0;
    for (let i = 0; i < text.length; i++) {
        hash = (hash * 31 + text.charCodeAt(i)) | 0;
    }
    return Math.abs(hash);
}

/** Repository-style source URL for glyphs/labels: GitHub repo wins, else EGO page. */
function getPreferredSourceUrl(item) {
    const github = getSource(item, 'github');
    if (github?.links?.repositoryUrl) {
        return github.links.repositoryUrl;
    }

    return getSource(item, 'ego')?.links?.pageUrl ?? '';
}

function renderPopover(item, compatible) {
    const screenshot = resolveAssetUrl(getScreenshotUrl(item));
    const shellLabel = getShellLabel(item, compatible);
    const updated = formatUpdatedAge(item.updatedAt);

    return `
        <div class="eh-popover" aria-hidden="true">
            <img class="eh-popover-shot" src="${escapeHtmlAttr(screenshot)}" alt="" loading="lazy">
            <div class="eh-popover-meta">
                <span>${shellLabel ? `${shellLabel} · ` : ''}${updated ? `updated ${updated}` : ''}</span>
                <span>${escapeHtml(getSourceLabel(item))}</span>
            </div>
        </div>
    `;
}

function renderInlineEmptyState() {
    return '<div class="eh-empty">No extensions match these filters.</div>';
}

/* ---------------------------------------------------------- Interactions */

// One observer per rendered list; disconnected when the next render
// replaces the load-more button so stale observers cannot double-fire.
let loadMoreObserver = null;

// Rotation timers of the hero sliders; cleared on every re-render because
// innerHTML replaces the slider elements the intervals still reference.
let heroSliderCleanups = [];

// Outside-click listeners of open compatibility menus (sidebar + mobile
// chip); released on the next render so detached menus cannot keep a
// document listener alive.
let compatMenuCleanups = [];

function bindInteractions(container, callbacks, filterState, pagination) {
    loadMoreObserver?.disconnect();
    loadMoreObserver = null;
    heroSliderCleanups.forEach((cleanup) => cleanup());
    heroSliderCleanups = [];
    compatMenuCleanups.forEach((cleanup) => cleanup());
    compatMenuCleanups = [];

    if (callbacks.onFilterChange) {
        container.querySelectorAll('[data-nav-preset]').forEach((button) => {
            button.addEventListener('click', () => {
                callbacks.onFilterChange({ preset: button.dataset.navPreset, category: null });
            });
        });

        container.querySelectorAll('[data-category]').forEach((button) => {
            button.addEventListener('click', () => {
                // Categories are browsed through the all-extensions index.
                callbacks.onFilterChange({ preset: 'all', category: button.dataset.category || null });
            });
        });

        bindSortMenu(container, callbacks);
    }

    container.querySelectorAll('[data-compat-menu]').forEach((widget) => {
        bindCompatMenu(widget, callbacks);
    });

    revealActiveChip(container);

    container.querySelectorAll('[data-card-uuid]').forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('[data-row-action]')) {
                return;
            }

            event.preventDefault();
            callbacks.onItemClick?.(card.dataset.cardUuid);
        });
    });

    container.querySelectorAll('[data-hero-detail]').forEach((button) => {
        button.addEventListener('click', (event) => {
            const hero = event.target.closest('[data-hero-uuid]');
            callbacks.onItemClick?.(hero.dataset.heroUuid);
        });
    });

    const loadMore = container.querySelector('#load-more');
    loadMore?.addEventListener('click', () => {
        callbacks.onPageChange?.(pagination.currentPage + 1);
    });

    // Auto-load when the button approaches the viewport; the button simply
    // disappears on the last page, which stops the loop naturally.
    if (loadMore && 'IntersectionObserver' in window) {
        loadMoreObserver = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                callbacks.onPageChange?.(pagination.currentPage + 1);
            }
        }, { rootMargin: '600px 0px' });
        loadMoreObserver.observe(loadMore);
    }

    bindHeroSliders(container);
}

function bindSortMenu(container, callbacks) {
    const sortWidget = container.querySelector('[data-sort-menu]');
    if (!sortWidget) {
        return;
    }

    const toggle = sortWidget.querySelector('[data-sort-toggle]');
    const close = () => {
        sortWidget.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        const open = sortWidget.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(open));
    });

    sortWidget.querySelectorAll('[data-sort-option]').forEach((option) => {
        option.addEventListener('click', () => {
            close();
            callbacks.onFilterChange({ sortBy: option.dataset.sortOption });
        });
    });

    document.addEventListener('click', close, { once: true });
    sortWidget.addEventListener('click', (event) => event.stopPropagation());
}

/**
 * Compatibility dropdown: opens on the trigger, closes on selection, on a
 * click outside the panel and on Escape. Choosing an entry hands the raw
 * shell version (or null for "Any GNOME version") to the caller.
 */
function bindCompatMenu(widget, callbacks) {
    if (!callbacks.onShellVersionChange) {
        return;
    }

    const toggle = widget.querySelector('[data-compat-toggle]');
    const track = widget.closest('[data-mobilenav-track]');

    const onDocumentClick = (event) => {
        if (!widget.contains(event.target)) {
            close();
        }
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            close();
            toggle.focus();
        }
    };

    const detach = () => {
        document.removeEventListener('click', onDocumentClick);
        document.removeEventListener('keydown', onKeyDown);
        track?.removeEventListener('scroll', close);
        compatMenuCleanups = compatMenuCleanups.filter((entry) => entry !== detach);
    };

    const close = () => {
        widget.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        detach();
    };

    const open = () => {
        widget.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        document.addEventListener('click', onDocumentClick);
        document.addEventListener('keydown', onKeyDown);
        // The chip scrolls with the strip, a fixed menu does not follow it.
        track?.addEventListener('scroll', close);
        compatMenuCleanups.push(detach);

        const menu = widget.querySelector('.eh-compat-menu');
        placeFixedMenu(menu, toggle);

        // The version list covers every declared release, so the current
        // selection is usually far outside the scroll window.
        const active = widget.querySelector('[data-compat-option].is-active');
        if (menu && active) {
            menu.scrollTop = Math.max(0, active.offsetTop - menu.clientHeight / 2);
        }
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        if (widget.classList.contains('is-open')) {
            close();
        } else {
            open();
        }
    });

    widget.querySelectorAll('[data-compat-option]').forEach((option) => {
        option.addEventListener('click', () => {
            close();
            const value = option.dataset.compatOption;
            callbacks.onShellVersionChange(value === ANY_SHELL_OPTION ? null : value);
        });
    });
}

/**
 * The mobile compatibility menu is `position: fixed` so the chip strip's
 * `overflow-x` cannot clip it. Fixed means viewport coordinates, so the menu
 * is placed under its trigger here and clamped into the visible area. The
 * sidebar menu is absolutely positioned and skips this.
 */
function placeFixedMenu(menu, toggle) {
    if (!menu || getComputedStyle(menu).position !== 'fixed') {
        return;
    }

    const GAP = 6;
    const EDGE = 14;
    const trigger = toggle.getBoundingClientRect();
    const width = menu.offsetWidth;
    const maxLeft = Math.max(EDGE, document.documentElement.clientWidth - width - EDGE);

    // Right-aligned to the trigger, pulled back inside both viewport edges.
    menu.style.left = `${Math.min(Math.max(EDGE, trigger.right - width), maxLeft)}px`;
    menu.style.top = `${trigger.bottom + GAP}px`;
}

/**
 * The chip strip holds five presets plus eight categories, so the active
 * entry is usually scrolled out of sight after a category jump. Scrolling
 * the track (never `scrollIntoView`, which would also move the page) keeps
 * the current selection visible.
 */
function revealActiveChip(container) {
    const track = container.querySelector('[data-mobilenav-track]');
    // The compatibility chip also goes active while filtering, but it is the
    // last chip — revealing it would scroll every preset out of sight.
    const active = track?.querySelector('.eh-chip.is-active:not(.eh-chip--compat)');
    if (!track || !active) {
        return;
    }

    track.scrollLeft = Math.max(0, active.offsetLeft - (track.clientWidth - active.offsetWidth) / 2);
}

/**
 * Hero sliders auto-rotate every 6s, paused while hovered or focused;
 * dots switch slides manually. No prev/next arrows in the final design.
 *
 * Timer is a self-rescheduling setTimeout chain (no setInterval stacking),
 * fully stopped while paused/hidden and restarted by the unpause/visibility
 * handlers, so background tabs never queue up missed rotations.
 */
function bindHeroSliders(container) {
    container.querySelectorAll('[data-hero-slider]').forEach((slider) => {
        const slides = Array.from(slider.querySelectorAll('.eh-hero-slide'));
        const dots = Array.from(slider.querySelectorAll('[data-hero-dot]'));
        if (slides.length < 2) {
            return;
        }

        const ROTATE_MS = 6000;
        let index = 0;
        let paused = false;
        let timer = null;

        const render = () => {
            slides.forEach((slide, i) => {
                slide.classList.toggle('is-active', i === index);
                slide.setAttribute('aria-hidden', String(i !== index));
            });
            dots.forEach((dot, i) => {
                dot.classList.toggle('is-active', i === index);
                dot.setAttribute('aria-selected', String(i === index));
            });
        };

        const goTo = (nextIndex) => {
            index = (nextIndex + slides.length) % slides.length;
            render();
        };

        const stop = () => {
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        };

        const schedule = () => {
            stop();
            timer = setTimeout(() => {
                timer = null;
                if (paused || document.hidden) {
                    return;
                }
                goTo(index + 1);
                schedule();
            }, ROTATE_MS);
        };

        const setPaused = (value) => {
            paused = value;
            if (value) {
                stop();
            } else {
                schedule();
            }
        };

        const onVisibilityChange = () => {
            if (document.hidden) {
                stop();
            } else if (!paused) {
                schedule();
            }
        };

        dots.forEach((dot) => {
            dot.addEventListener('click', () => {
                goTo(parseInt(dot.dataset.heroDot, 10));
                if (!paused) {
                    schedule();
                }
            });
        });

        bindHeroSwipe(slider, goTo, setPaused, () => index);

        // Hover and keyboard focus pause independently; only unpause when
        // neither is present, otherwise focusout/mouseleave would restart
        // the rotation while the user still interacts with the slider.
        slider.addEventListener('mouseenter', () => setPaused(true));
        slider.addEventListener('mouseleave', () => {
            if (!slider.matches(':focus-within')) {
                setPaused(false);
            }
        });
        slider.addEventListener('focusin', () => setPaused(true));
        slider.addEventListener('focusout', () => {
            if (!slider.matches(':focus-within') && !slider.matches(':hover')) {
                setPaused(false);
            }
        });
        document.addEventListener('visibilitychange', onVisibilityChange);

        schedule();
        heroSliderCleanups.push(() => {
            stop();
            document.removeEventListener('visibilitychange', onVisibilityChange);
        });
    });
}

/**
 * Swipe navigation for the hero sliders.
 *
 * The slides are crossfaded inside a single grid cell, so there is no native
 * scroller a finger could drag; a horizontal pointer drag is translated into
 * the same `goTo(±1)` the dots use. The gesture only exists in the mobile
 * layout (the range that also carries the chip strip) and never for mouse
 * pointers, so the desktop slider keeps dots as its only control and text
 * selection, the screenshot lightbox and hover-pause stay untouched — on a
 * touchscreen desktop too.
 */
function bindHeroSwipe(slider, goTo, setPaused, getIndex) {
    // Below this the gesture is a tap; a drag that runs more vertically than
    // horizontally is the page scrolling and must be left alone.
    const MIN_DISTANCE = 45;
    const mobileLayout = window.matchMedia(MOBILE_NAV_QUERY);

    let startX = 0;
    let startY = 0;
    // `gesture` stays true for the whole pointer down/up cycle, `tracking`
    // only until the swipe resolves — without the first flag a plain mouse
    // click on the desktop hero would end a gesture it never started and
    // restart the rotation while the cursor is still hovering.
    let gesture = false;
    let tracking = false;
    let swipedAt = 0;

    slider.addEventListener('pointerdown', (event) => {
        if (!mobileLayout.matches || !event.isPrimary || event.pointerType === 'mouse') {
            return;
        }

        gesture = true;
        tracking = true;
        swipedAt = 0;
        startX = event.clientX;
        startY = event.clientY;
        setPaused(true);
    });

    slider.addEventListener('pointermove', (event) => {
        if (!tracking) {
            return;
        }

        const deltaX = event.clientX - startX;
        const deltaY = event.clientY - startY;

        if (Math.abs(deltaY) > Math.abs(deltaX)) {
            tracking = false;
            return;
        }

        if (Math.abs(deltaX) < MIN_DISTANCE) {
            return;
        }

        goTo(getIndex() + (deltaX < 0 ? 1 : -1));
        tracking = false;
        swipedAt = Date.now();
    });

    const endGesture = () => {
        if (!gesture) {
            return;
        }

        gesture = false;
        tracking = false;
        setPaused(false);
    };

    slider.addEventListener('pointerup', endGesture);
    slider.addEventListener('pointercancel', endGesture);

    // A finished swipe still produces a click on the element under the
    // finger — the CTA or the lightbox anchor. Swallow that one, but only
    // inside a short window after the swipe: a swipe does not always emit a
    // click, and a sticky flag would then eat the user's next real tap (a
    // dot, for instance).
    const CLICK_SUPPRESS_MS = 400;

    slider.addEventListener('click', (event) => {
        if (Date.now() - swipedAt > CLICK_SUPPRESS_MS) {
            return;
        }

        swipedAt = 0;
        event.preventDefault();
        event.stopPropagation();
    }, true);
}

/* -------------------------------------------------------------- Helpers */

/**
 * Latest stable GNOME major: the highest major that a meaningful share of
 * the snapshot declares support for. Ignores pre-release majors (e.g. a
 * major with <5% coverage) so "compatible" does not dim almost every row.
 */
function findCurrentGnomeMajor(items) {
    const counts = new Map();
    const total = items.length || 1;

    for (const item of items) {
        for (const major of getNumericMajors(item.supportedShellVersions)) {
            counts.set(major, (counts.get(major) ?? 0) + 1);
        }
    }

    let current = null;
    for (const major of Array.from(counts.keys()).sort((a, b) => a - b)) {
        if ((counts.get(major) ?? 0) / total >= 0.05) {
            current = major;
        }
    }

    return current;
}

/**
 * Compatibility rule: the extension must declare support for the detected
 * current shell via an explicit major-only version entry (e.g. "50").
 */
function isCompatible(item, currentGnomeMajor) {
    if (currentGnomeMajor === null) {
        return true;
    }

    return getNumericMajors(item.supportedShellVersions).includes(currentGnomeMajor);
}

/** Major-only shell versions ("50"), excluding dotted entries like "3.36". */
function getNumericMajors(versions) {
    const majors = [];
    for (const raw of versions ?? []) {
        const value = String(raw).trim();
        if (/^\d+$/.test(value)) {
            majors.push(Number.parseInt(value, 10));
        }
    }
    return majors.sort((a, b) => a - b);
}

function getShellLabel(item, compatible) {
    const majors = getNumericMajors(item.supportedShellVersions);
    if (majors.length === 0) {
        return '';
    }

    const min = majors[0];
    const max = majors[majors.length - 1];
    if (compatible) {
        return min === max ? `GNOME ${min}` : `GNOME ${min}–${max}`;
    }

    return `up to ${max}`;
}

function isOfficialGnomeSource(item) {
    return (item.sources ?? []).some((source) => {
        const urls = [source.links?.repositoryUrl, source.links?.pageUrl].filter(Boolean);
        return urls.some((url) => url.includes('gitlab.gnome.org/GNOME') || url.includes('git.gnome.org/gnome-shell-extensions'));
    });
}

function getSourceLabel(item) {
    if (isOfficialGnomeSource(item)) {
        return 'GNOME · gitlab.gnome.org';
    }

    return getSourceHost(item);
}

function getSourceHost(item) {
    const source = getPreferredSourceUrl(item);
    if (!source) {
        return 'extensions.gnome.org';
    }

    try {
        return new URL(source).host;
    } catch {
        return source;
    }
}

function countGitHubSources(items) {
    return items.reduce((count, item) => (getSource(item, 'github') ? count + 1 : count), 0);
}

function countEgoSources(items) {
    return items.reduce((count, item) => (getSource(item, 'ego') ? count + 1 : count), 0);
}

function resolveAssetUrl(path) {
    if (!path) {
        return null;
    }

    if (path.startsWith('http://') || path.startsWith('https://')) {
        return path;
    }

    if (path.startsWith('/')) {
        return `https://extensions.gnome.org${path}`;
    }

    return `/${path}`;
}

/** Locale-independent count formatting: English comma grouping everywhere. */
function formatCount(num) {
    return Number(num ?? 0).toLocaleString('en-US');
}

function formatSnapshotDate(generatedAt) {
    const parsed = Date.parse(generatedAt);
    if (Number.isNaN(parsed)) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(parsed));
}

function formatCompactCount(num) {
    if (num >= 1000000) {
        return `${(num / 1000000).toFixed(1)}M`;
    }
    if (num >= 1000) {
        return `${(num / 1000).toFixed(1)}K`;
    }
    return String(num);
}

function formatUpdatedAge(isoDate) {
    const parsed = Date.parse(isoDate);
    if (Number.isNaN(parsed)) {
        return '';
    }

    const diffDays = Math.max(0, Math.floor((Date.now() - parsed) / (1000 * 60 * 60 * 24)));
    if (diffDays === 0) {
        return 'today';
    }
    if (diffDays === 1) {
        return '1 day ago';
    }
    if (diffDays < 30) {
        return `${diffDays} days ago`;
    }

    const months = Math.floor(diffDays / 30);
    if (months < 12) {
        return months === 1 ? '1 month ago' : `${months} months ago`;
    }

    const years = Math.floor(months / 12);
    return years === 1 ? '1 year ago' : `${years} years ago`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeHtmlAttr(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
