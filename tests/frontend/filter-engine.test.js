/**
 * Regression tests for search result ranking in filter-engine.js.
 *
 * Node builtin test runner, no extra dependencies:
 *   node --test tests/frontend/filter-engine.test.js
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

import { applyFilters, normalizeQueryState } from '../../assets/app/filter-engine.js';

const SNAPSHOT_PATH = path.join(path.dirname(fileURLToPath(import.meta.url)), '../../public/data/extensions.v2.json');

/** Real production snapshot items, loaded once and reused by real-data tests. */
function loadSnapshotItems() {
    const data = JSON.parse(readFileSync(SNAPSHOT_PATH, 'utf8'));
    return Array.isArray(data) ? data : data.items;
}

/** Build a minimal but realistic snapshot item for search-ranking tests. */
function item({ name, description = '', creator = '', score = 0 }) {
    return { name, description, creator, uuid: `${name.toLowerCase()}@test`, score };
}

function namesOf(result) {
    return result.items.map((entry) => entry.name);
}

/**
 * Build a snapshot item with EGO/GitHub source metrics and media flags, for
 * quality-boost tests. Omitted metrics stay unmeasured (undefined), matching
 * the real snapshot's "omit instead of serialize 0/null" contract.
 */
function ratedItem({ name, description = '', creator = '', score = 0, rating, comments, downloads, stars, screenshot = false, icon = false }) {
    const egoMetrics = {};
    if (rating !== undefined) egoMetrics.rating = rating;
    if (comments !== undefined) egoMetrics.comments = comments;
    if (downloads !== undefined) egoMetrics.downloads = downloads;

    const sources = [
        {
            sourceType: 'ego',
            metrics: egoMetrics,
            displayScreenshot: screenshot ? 'https://example.invalid/screenshot.png' : null,
            displayIcon: icon ? 'https://example.invalid/icon.png' : null,
            links: { pageUrl: 'https://example.invalid/page', installUrl: 'https://example.invalid/install' },
        },
    ];

    if (stars !== undefined) {
        sources.push({
            sourceType: 'github',
            metrics: { stars },
            links: { repositoryUrl: 'https://example.invalid/repo' },
        });
    }

    return { name, description, creator, uuid: `${name.toLowerCase()}@test`, score, sources };
}

test('search: a direct name hit outranks a description-only hit, even at much lower popularity', () => {
    const items = [
        item({
            name: 'Wifi QR Code',
            description: 'Show a QR code for the active WiFi connection. Also lets you copy the code to clipboard.',
            creator: 'glerro',
            score: 90,
        }),
        item({
            name: 'Clipboard Indicator',
            description: 'Adds a clipboard indicator to the top panel.',
            creator: 'Tudmotu',
            score: 20,
        }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters(items, filterState);

    assert.deepEqual(namesOf(result), ['Clipboard Indicator', 'Wifi QR Code']);
});

test('search: items with equal relevance keep the existing popularity order (tie-break)', () => {
    const items = [
        item({ name: 'Clipboard History', description: 'Keeps a clipboard history.', creator: 'a', score: 50 }),
        item({ name: 'Clipboard Manager', description: 'Manages your clipboard.', creator: 'b', score: 90 }),
        item({ name: 'Gnome Clipboard', description: 'A clipboard extension.', creator: 'c', score: 70 }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters(items, filterState);

    // All three match "clipboard" as a whole word in the name (same relevance
    // tier), so the existing popularity sort must decide the order.
    assert.deepEqual(namesOf(result), ['Clipboard Manager', 'Gnome Clipboard', 'Clipboard History']);
});

test('search: a whole-word name match outranks a mere name-substring match', () => {
    const items = [
        // "board" is only a substring of "Clipboard", not a standalone word.
        item({ name: 'Clipboard Sync', description: 'Sync your clipboard across devices.', creator: 'a', score: 99 }),
        // "board" is a standalone word here.
        item({ name: 'Score Board', description: 'Displays a score board widget.', creator: 'b', score: 1 }),
    ];

    const filterState = normalizeQueryState({ search: 'board' }, 20);
    const result = applyFilters(items, filterState);

    assert.deepEqual(namesOf(result), ['Score Board', 'Clipboard Sync']);
});

test('search: whole-word boundary is Unicode-aware, not just ASCII \\b', () => {
    const items = [
        // "café" is only a substring of "Cafétopia", not a standalone word.
        item({ name: 'Cafétopia', description: 'A cafe-themed extension.', creator: 'a', score: 99 }),
        // "café" is a standalone word here; \b alone would miss the é boundary.
        item({ name: 'My Café Timer', description: 'A timer for your café breaks.', creator: 'b', score: 1 }),
    ];

    const filterState = normalizeQueryState({ search: 'café' }, 20);
    const result = applyFilters(items, filterState);

    assert.deepEqual(namesOf(result), ['My Café Timer', 'Cafétopia']);
});

test('search: multi-word queries still require every term to match (AND filter) alongside ranking', () => {
    const items = [
        item({ name: 'Clipboard Manager', description: 'Manage your clipboard history.', creator: 'x', score: 10 }),
        item({ name: 'Clipboard Indicator', description: 'Shows current clipboard content.', creator: 'y', score: 99 }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard manager' }, 20);
    const result = applyFilters(items, filterState);

    // "Clipboard Indicator" never mentions "manager" anywhere, so it must be
    // filtered out entirely, regardless of its much higher popularity score.
    assert.deepEqual(namesOf(result), ['Clipboard Manager']);
});

// Verbatim descriptions from the production snapshot (public/data/extensions.v2.json)
// for the exact regression case reported against real data: none of these four
// items match "clipboard" in the name, so they used to tie at a flat
// "description hit" relevance and fall back to the (much higher) popularity
// score of Persian Calendar/Just Perfection — even though "clipboard" is only
// an incidental, deeply buried mention in both, while Copyous names it as the
// very first word of its (much shorter) description.
const DESC_COPYOUS = 'Modern Clipboard Manager for GNOME';
const DESC_WIFI_QR_CODE = 'This extension add a switch to the WiFi menu, in the GNOME system menu, that show a QR Code of the active connection.\n\nThis can be useful for quickly connecting devices capable of reading QR Code and applying the settings to the system, without having to type in the name and the password of the WiFi. (e.g. Android Smartphone). \n\nFrom version 4 added a functionality to copy the QR Code to clipboard with right click on it.';
const DESC_PERSIAN_CALENDAR = 'Displays Persian (Iranian/Jalali) calendar in the top panel\n\nIt offers:\n1. Displays the Persian/Iranian/Jalali calendar\n2. Holiday indicator\n3. Day change notifications\n4. Converts dates between the Persian, Gregorian, and Hijri (lunar) calendars\n5. Event listings:\n5.1. Official solar events\n5.2. Official lunar events\n5.3. Official international events\n5.4. Traditional Persian events\n5.5. Notable Persian figures\n\nThis extension writes to clipboard by user interaction.\n\nPlease "rate" the project here and give it a star on GitHub.\nIf you encounter any issues or have suggestions, feel free to open an issue there on GitHub!';
const DESC_JUST_PERFECTION = 'Tweak Tool to Customize GNOME Shell, Change the Behavior and Disable UI Elements\n\n- Accessibility Menu Visibility\n- Activities Button Icon (3.36-44)\n- Activities button Visibility\n- Alt Tab Icon Size\n- Alt Tab Window Preview Icon Size\n- Alt Tab Window Preview Size\n- Always Show Workspace Switcher on Dynamic Workspaces (40 and higher)\n- Animation Speed or Disable it\n- App Gesture (3.36, 3.38)\n- Applications Button Visibility\n- App Menu Icon Visibility (3.36-44)\n- App Menu Label Visibility (3.36-44)\n- App Menu Visibility (3.36-44)\n- Background Menu Visibility\n- Calendar Visibility\n- Clock Menu Position\n- Clock Menu Visibility\n- Dash Icon Size\n- Dash Separator Visibility (40 and higher)\n- Dash Visibility\n- Disable Overlay Key (40-42)\n- Disable Type to Search\n- Double Super Key to App Grid\n- Events in Clock Menu Visibility\n- GNOME Shell Theme Override\n- Hot Corner (3.36-40)\n- Keyboard Layout Visibility\n- Looking Glass Size\n- Notification Banner Position\n- OSD Position\n- OSD Visibility\n- Overview Spacing Size (40 and higher)\n- Panel Arrow Visibility (3.36, 3.38)\n- Panel Button Padding Size\n- Panel Height\n- Panel icon size \n- Panel Indicator Padding Size\n- Panel Notification icon Visibility\n- Panel Position\n- Panel Round Corner Size (3.36-41)\n- Panel Visibility\n- Panel Visibility in Overview\n- Power Icon Visibility\n- Quick Settings Airplane Mode Toggle Visibility (45 and higher)\n- Quick Settings Menu Visibility (43 and higher)\n- Quick Settings Dark Mode Toggle Visibility (45 and higher)\n- Quick Settings Night Light Toggle Visibility (45 and higher)\n- Ripple Box\n- Search Visibility\n- Startup Status (40-44)\n- Switcher Popup Delay\n- System Menu (Aggregate Menu) Visibility (3.36-42)\n- Take Screenshot Button in Window Menu Visibility\n- Weather Visibility\n- Window Demands Attention Focus\n- Window Maximized on Create (45 and higher)\n- Window Menu Visibility (45 and higher)\n- Window Picker Caption Visibility\n- Window Picker Close Button Visibility\n- Window Picker Icon (40-44)\n- Workspace Background Corner Size in Overview (40 and higher)\n- Workspace Peek (42-44)\n- Workspace Popup Visibility\n- Workspaces in app grid Visibility (40 and higher)\n- Workspace Switcher Click To Main View (45 and higher)\n- Workspace Switcher Size (40-44)\n- Workspace Switcher Visibility\n- Workspace Wraparound\n- World Clock Visibility\n\nThis extension uses the clipboard in the preferences window to allow you to copy the support address.';

test('search: an early/focused description hit outranks a deeply buried mention, even at much lower popularity (real snapshot regression)', () => {
    const items = [
        item({ name: 'Persian Calendar', description: DESC_PERSIAN_CALENDAR, creator: 'iamrezamousavi', score: 93 }),
        item({ name: 'Just Perfection', description: DESC_JUST_PERFECTION, creator: 'just-perfection', score: 85 }),
        item({ name: 'Wifi QR Code', description: DESC_WIFI_QR_CODE, creator: 'glerro', score: 84 }),
        item({ name: 'Copyous', description: DESC_COPYOUS, creator: 'boerdereinar', score: 82 }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters(items, filterState);

    // Copyous names "Clipboard" as the very first word of a 34-character
    // description; the other three only mention it once, deep inside a much
    // longer, unrelated text. Copyous must lead despite the lowest score.
    assert.equal(namesOf(result)[0], 'Copyous');
    assert.ok(namesOf(result).indexOf('Copyous') < namesOf(result).indexOf('Wifi QR Code'));
    assert.ok(namesOf(result).indexOf('Copyous') < namesOf(result).indexOf('Persian Calendar'));
    assert.ok(namesOf(result).indexOf('Copyous') < namesOf(result).indexOf('Just Perfection'));
});

test('search: a name hit must outrank a pure multi-term description match, no matter how many description terms accumulate', () => {
    const terms = ['notes', 'sync', 'cloud', 'backup', 'photo', 'tasks'];

    // All six terms bunched at the very start of a short description: naive
    // per-term addition lets enough "early description hit" scores pile up
    // to outweigh a single name hit once a query has enough words.
    const allTermsUpFront = `${terms.join(' ')}.`;

    // "notes" is a genuine name hit; the other five terms only ever appear
    // once, buried at the very end of a long, unrelated filler text.
    const restBuriedLate = `${'unrelated filler text '.repeat(120)}${terms.slice(1).join(' ')}.`;

    const items = [
        item({ name: 'Notes App', description: restBuriedLate, creator: 'x', score: 10 }),
        item({ name: 'Widget Manager Pro', description: allTermsUpFront, creator: 'y', score: 99 }),
    ];

    const filterState = normalizeQueryState({ search: terms.join(' ') }, 20);
    const result = applyFilters(items, filterState);

    // A real name hit for one term always outranks an item that only ever
    // matched in the description, regardless of term count or popularity.
    assert.deepEqual(namesOf(result), ['Notes App', 'Widget Manager Pro']);
});

test('search: a well-reviewed, screenshotted description hit outranks a bare, unrated one with a near-identical description position (real regression: Copyous vs ClusterCut)', () => {
    const items = [
        // Verbatim from the production snapshot: "clipboard" sits at
        // roughly the same relative position as in Copyous's description
        // (~0.82 vs ~0.79) — close enough that quality decides between them,
        // per the hybrid/tolerance ranking (quality is a nudge, not a tier).
        ratedItem({
            name: 'ClusterCut',
            description: 'Integration for ClusterCut Clipboard Sync. Adds a Quick Settings toggle and reads/writes the system clipboard to enable clipboard sync on Wayland.',
            creator: 'Keith Vassallo',
            score: 40,
            rating: 0,
            comments: 0,
            downloads: 266,
            screenshot: true,
            icon: true,
        }),
        ratedItem({
            name: 'Copyous',
            description: 'Modern Clipboard Manager for GNOME',
            creator: 'boerdereinar',
            score: 82,
            rating: 4.78,
            comments: 54,
            downloads: 35068,
            screenshot: true,
            icon: true,
        }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters(items, filterState);

    assert.deepEqual(namesOf(result), ['Copyous', 'ClusterCut']);
});

test('search: hybrid/tolerance ranking on the real production snapshot — Copyous leads ClusterCut, Persian Calendar and Just Perfection for "clipboard" (Option B core result)', () => {
    const snapshotItems = loadSnapshotItems();

    // Real items straight from public/data/extensions.v2.json. Persian
    // Calendar (105k downloads, 5.0 rating, 546 GitHub stars) and Just
    // Perfection (1.97M downloads) are the two high-quality extensions that
    // used to outrank Copyous under Option A (quality as its own tuple
    // index, ahead of description position). "Clipboard" is only ever an
    // incidental, deeply buried mention in their descriptions.
    const copyous = snapshotItems.find((entry) => entry.uuid === 'copyous@boerdereinar.dev');
    const clusterCut = snapshotItems.find((entry) => entry.uuid === 'clustercut@keithvassallo.com');
    const persianCalendar = snapshotItems.find((entry) => entry.uuid === 'PersianCalendar@oxygenws.com');
    const justPerfection = snapshotItems.find((entry) => entry.uuid === 'just-perfection-desktop@just-perfection');

    assert.ok(copyous, 'fixture drift: Copyous missing from public/data/extensions.v2.json');
    assert.ok(clusterCut, 'fixture drift: ClusterCut missing from public/data/extensions.v2.json');
    assert.ok(persianCalendar, 'fixture drift: Persian Calendar (oxygenws) missing from public/data/extensions.v2.json');
    assert.ok(justPerfection, 'fixture drift: Just Perfection missing from public/data/extensions.v2.json');

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters([copyous, clusterCut, persianCalendar, justPerfection], filterState);
    const order = namesOf(result);

    // Core Option B result: description position is the main signal, so
    // Copyous (an early, focused "clipboard" mention) leads ClusterCut (a
    // similarly early mention, decided by quality within the tolerance
    // band), well ahead of Persian Calendar and Just Perfection, whose
    // "clipboard" mention is buried deep in a much longer description.
    assert.deepEqual(order, ['Copyous', 'ClusterCut', 'Persian Calendar', 'Just Perfection']);
});

test('search: among equal description hits, quality signals (rating, reviews, downloads, screenshot, icon) decide', () => {
    const items = [
        ratedItem({ name: 'Bare Extension', description: 'A clipboard helper with no extra polish.', creator: 'a', score: 50 }),
        ratedItem({
            name: 'Polished Extension',
            description: 'A clipboard helper with no extra polish.',
            creator: 'b',
            score: 50,
            rating: 4.5,
            comments: 30,
            downloads: 20000,
            screenshot: true,
            icon: true,
        }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters(items, filterState);

    assert.deepEqual(namesOf(result), ['Polished Extension', 'Bare Extension']);
});

test('search: a name-substring hit still outranks a high-quality description-only hit', () => {
    const items = [
        // "clipboard" is only a name substring, not a whole word ("boardclipboard").
        ratedItem({ name: 'BoardClipboard', description: 'Unrelated description text.', creator: 'a', score: 10 }),
        ratedItem({
            name: 'Super Tool',
            description: 'A clipboard extension with excellent reviews.',
            creator: 'b',
            score: 10,
            rating: 5,
            comments: 200,
            downloads: 100000,
            stars: 1000,
            screenshot: true,
            icon: true,
        }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters(items, filterState);

    assert.deepEqual(namesOf(result), ['BoardClipboard', 'Super Tool']);
});

test('search: a pure creator-only hit never outranks a real (even deeply buried, low-quality) description hit, no matter its quality (Option B fix)', () => {
    const buriedLate = `${'unrelated filler text '.repeat(150)}clipboard.`;

    const items = [
        // Creator-only match: "clipboard" never appears in name or
        // description, only in the creator field. High quality (rating,
        // reviews, downloads, stars, screenshot, icon) used to leak into the
        // description tuple slot via the unconditional nudge, letting a
        // creator-only hit outrank a real description hit.
        ratedItem({
            name: 'Totally Unrelated Tool',
            description: 'Nothing here matches the search term at all.',
            creator: 'ClipboardMaster',
            score: 5,
            rating: 5,
            comments: 200,
            downloads: 100000,
            stars: 1000,
            screenshot: true,
            icon: true,
        }),
        // Real description hit, but buried deep in a long filler text and
        // completely unrated — the lowest-quality description hit possible.
        ratedItem({
            name: 'Another Tool',
            description: buriedLate,
            creator: 'someone-else',
            score: 5,
        }),
    ];

    const filterState = normalizeQueryState({ search: 'clipboard' }, 20);
    const result = applyFilters(items, filterState);

    // The description hit belongs to a strictly higher tier than the
    // creator-only hit and must lead, regardless of quality on either side.
    assert.deepEqual(namesOf(result), ['Another Tool', 'Totally Unrelated Tool']);
});

test('non-search listing keeps the plain popularity sort untouched', () => {
    const items = [
        item({ name: 'Low Score', score: 10 }),
        item({ name: 'High Score', score: 99 }),
    ];

    const filterState = normalizeQueryState({}, 20);
    const result = applyFilters(items, filterState);

    assert.deepEqual(namesOf(result), ['High Score', 'Low Score']);
});
