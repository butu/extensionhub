/**
 * Detail View Module
 *
 * Renders a single extension detail page in the reference look: back button,
 * detail head (icon, title, byline, source metrics, primary action), the
 * lightbox screenshot hero with blurred backdrop, and a two-column content
 * area (About · Reviews · "More by" rows | facts sidebar).
 *
 * Everything is source-scoped and presence-based: a missing link, fact or
 * metric renders nothing at all instead of a placeholder value.
 */

import {
    getSource,
    getPrimaryAction,
    getDetailPath,
    getIconUrl,
    getScreenshotUrl,
} from './snapshot-loader.js';

/** Reviews visible before the reveal button is used. */
const INITIAL_VISIBLE_REVIEWS = 5;

/** Shell versions listed verbatim in the facts sidebar before "+ N more". */
const FACT_SHELL_VERSIONS = 3;

// Same fixed palette as the list rows, so an extension keeps one identity
// colour across views.
const LETTER_TILE_PALETTE = ['#613583', '#1c71d8', '#2ec27e', '#c64600', '#986a44', '#e5a50a', '#a51d2d'];

const ICONS = {
    chevronLeft: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>',
    chevronDown: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    puzzle: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 15.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682a2.414 2.414 0 0 1-3.414 0L8.61 19.61a1 1 0 0 0-1.68.474 2.5 2.5 0 1 1-3.014-3.015 1 1 0 0 0 .474-1.68l-1.683-1.682a2.414 2.414 0 0 1 0-3.414L4.39 8.61a1 1 0 0 1 1.68.474 2.5 2.5 0 1 0 3.014-3.015 1 1 0 0 1-.474-1.68l1.683-1.682a2.414 2.414 0 0 1 3.414 0z"/></svg>',
    download: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>',
    tag: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
    github: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>',
    star: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.967a1 1 0 00.95.69h4.175c.969 0 1.371 1.24.588 1.81l-3.38 2.455a1 1 0 00-.364 1.118l1.287 3.966c.3.922-.755 1.688-1.54 1.118l-3.38-2.454a1 1 0 00-1.175 0l-3.38 2.454c-.784.57-1.838-.196-1.54-1.118l1.287-3.966a1 1 0 00-.364-1.118L2.05 9.394c-.783-.57-.38-1.81.588-1.81h4.175a1 1 0 00.95-.69l1.286-3.967z"/></svg>',
    external: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>',
};

export function renderDetailView(container, item, relatedItems = [], callbacks = {}) {
    if (!item) {
        container.innerHTML = '<div class="eh-detail"><p class="eh-empty">Extension not found</p></div>';
        return;
    }

    const screenshot = resolveAssetUrl(getScreenshotUrl(item));

    container.innerHTML = `
        <div class="eh-detail">
            <button type="button" id="back-btn" class="eh-detail-back" aria-label="Back to extension list">
                ${ICONS.chevronLeft}Back to list
            </button>

            ${renderDetailHead(item)}

            ${screenshot ? renderScreenshotHero(item, screenshot) : ''}

            <div class="eh-detail-body">
                <div class="eh-detail-main">
                    ${renderAbout(item)}

                    <section data-comments-section class="eh-detail-reviews">
                        <h2 class="eh-detail-h2">Reviews</h2>
                        <p class="eh-review-empty">Loading reviews…</p>
                    </section>

                    ${relatedItems.length > 0 ? renderRelatedExtensions(item.creator, relatedItems) : ''}
                </div>

                ${renderFactsSidebar(item)}
            </div>
        </div>
    `;

    container.querySelector('#back-btn')?.addEventListener('click', callbacks.onGoBack);
    container.querySelectorAll('[data-related-uuid]').forEach((node) => {
        node.addEventListener('click', (event) => {
            if (!callbacks.onOpenRelated) {
                return;
            }

            event.preventDefault();
            callbacks.onOpenRelated(node.dataset.relatedUuid);
        });
    });
    container.querySelectorAll('[data-fact-expand]').forEach((button) => {
        button.addEventListener('click', () => {
            button.textContent = button.dataset.expandedValue;
            button.setAttribute('aria-expanded', 'true');
        });
    });
}

/* ------------------------------------------------------------------ Head */

function renderDetailHead(item) {
    const primaryAction = getPrimaryAction(item);

    return `
        <div class="eh-detail-head">
            ${renderDetailIcon(item)}

            <div class="eh-detail-headtext">
                <h1 class="eh-detail-title">${escapeHtml(item.name)}</h1>
                ${renderByline(item)}
                ${renderHeadMetrics(item)}
            </div>

            ${primaryAction
                ? `<div class="eh-detail-actions">
                       <a class="eh-detail-install" href="${escapeHtmlAttr(primaryAction.url)}" target="_blank" rel="noopener noreferrer">
                           ${ICONS.download}${escapeHtml(getPrimaryActionLabel(item, primaryAction))}
                       </a>
                   </div>`
                : ''}
        </div>
    `;
}

/**
 * EGO install is the canonical action; GitHub-only items get their release or
 * repository link instead, labelled for what it actually does.
 */
function getPrimaryActionLabel(item, primaryAction) {
    if (getSource(item, 'ego')) {
        return 'Install extension';
    }

    return primaryAction.url === getSource(item, 'github')?.links?.repositoryUrl
        ? 'View repository'
        : 'Download latest release';
}

function renderDetailIcon(item) {
    const icon = resolveAssetUrl(getIconUrl(item));
    const isGeneric = !icon || icon.includes('/static/') || icon.includes('plugin.png');

    if (!isGeneric) {
        return `<span class="eh-detail-icon" aria-hidden="true"><img src="${escapeHtmlAttr(icon)}" alt=""></span>`;
    }

    const letter = escapeHtml((item.name || '?').trim().charAt(0).toUpperCase());
    const screenshot = resolveAssetUrl(getScreenshotUrl(item));

    // Same fallback ladder as the list rows: blurred screenshot backdrop with
    // the initial on top, colored letter tile only when there is no screenshot.
    if (screenshot) {
        return `
            <span class="eh-detail-icon eh-detail-icon-shot" aria-hidden="true">
                <img src="${escapeHtmlAttr(screenshot)}" alt="">
                <span class="eh-detail-icon-letter">${letter}</span>
            </span>
        `;
    }

    const color = LETTER_TILE_PALETTE[stableTextHash(item.uuid ?? item.name ?? '') % LETTER_TILE_PALETTE.length];

    return `<span class="eh-detail-icon" style="background-color: ${color}" aria-hidden="true"><span class="eh-detail-icon-letter">${letter}</span></span>`;
}

function renderByline(item) {
    const parts = [];

    if (item.creator) {
        const creatorUrl = resolveEgoUrl(item.creatorUrl);
        parts.push(creatorUrl
            ? `<span>by <a href="${escapeHtmlAttr(creatorUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.creator)}</a></span>`
            : `<span>by ${escapeHtml(item.creator)}</span>`);
    }

    const updated = formatUpdatedAge(item.updatedAt);
    if (updated) {
        parts.push(`<span>updated ${escapeHtml(updated)}</span>`);
    }

    if (parts.length === 0) {
        return '';
    }

    return `<div class="eh-detail-byline">${parts.join(`<span class="eh-detail-sep" aria-hidden="true">·</span>`)}</div>`;
}

/**
 * EGO downloads and GitHub stars are separate source metrics and are never
 * merged into one number. Each one renders only when its own source measured
 * it; the 7-day delta is an optional addition on top, not a precondition.
 */
function renderHeadMetrics(item) {
    const parts = [renderRating(item)];

    const downloads = readMetric(item, 'ego', 'downloads');
    if (downloads !== null) {
        parts.push(`
            <span class="eh-detail-metric">
                ${formatCount(downloads)} downloads
                ${renderWeekDelta(readMetric(item, 'ego', 'downloadsDelta7d'))}
            </span>
        `);
    }

    const stars = readMetric(item, 'github', 'stars');
    if (stars !== null) {
        parts.push(`
            <span class="eh-detail-metric" title="GitHub stars">
                <span class="eh-detail-metric-icons" aria-hidden="true">${ICONS.github}<span class="eh-detail-star">${ICONS.star}</span></span>
                ${formatCount(stars)} stars
                ${renderWeekDelta(readMetric(item, 'github', 'starsDelta7d'))}
            </span>
        `);
    }

    const rendered = parts.filter((part) => part !== '');
    if (rendered.length === 0) {
        return '';
    }

    return `<div class="eh-detail-metrics">${rendered.join('')}</div>`;
}

function renderRating(item) {
    const rating = readMetric(item, 'ego', 'rating');
    const comments = readMetric(item, 'ego', 'comments');

    if (rating === null || rating <= 0) {
        // EGO items legitimately have no rating yet; GitHub-only items have no
        // rating dimension at all, so they get no slot either.
        return getSource(item, 'ego')
            ? '<span class="eh-detail-rating-label">No rating yet</span>'
            : '';
    }

    return `
        <span class="eh-detail-rating">
            <span class="eh-stars eh-stars--detail" role="img" aria-label="Rated ${rating.toFixed(1)} of 5">
                <span class="eh-stars-dim" aria-hidden="true"></span>
                <span class="eh-stars-fill" style="width: ${(rating / 5) * 100}%" aria-hidden="true"></span>
            </span>
            <span class="eh-detail-rating-label">${rating.toFixed(1)}${comments !== null ? ` · ${formatCount(comments)} reviews` : ''}</span>
        </span>
    `;
}

/** Week deltas exist only once a 7-day baseline was collected. */
function renderWeekDelta(delta) {
    if (delta === null) {
        return '';
    }

    return `<span class="eh-detail-delta">${delta < 0 ? '' : '+'}${formatCount(delta)} this week</span>`;
}

/* ------------------------------------------------------------------ Hero */

/**
 * The hero stays an `<a class="glightbox">` so the globally initialised
 * lightbox picks it up again on `window.__ehLightbox.reload()`.
 *
 * The anchor is the lightbox trigger and announces the action itself.
 */
function renderScreenshotHero(item, screenshot) {
    return `
        <a href="${escapeHtmlAttr(screenshot)}"
           class="glightbox eh-detail-shot"
           aria-label="Open ${escapeHtmlAttr(item.name)} screenshot">
            <span class="eh-detail-shot-blur" style="background-image: url('${escapeCssUrl(screenshot)}');" aria-hidden="true"></span>
            <img class="eh-detail-shot-img" src="${escapeHtmlAttr(screenshot)}" alt="${escapeHtmlAttr(item.name)} screenshot">
        </a>
    `;
}

/* ----------------------------------------------------------------- About */

/** The snapshot carries one description; it is shown in full, never invented. */
function renderAbout(item) {
    const description = String(item.description ?? '').trim();
    if (description === '') {
        return '';
    }

    return `
        <section class="eh-detail-about">
            <h2 class="eh-detail-h2">About this extension</h2>
            <div class="eh-detail-desc">${escapeHtml(description)}</div>
        </section>
    `;
}

/* -------------------------------------------------------------- More by */

function renderRelatedExtensions(creator, relatedItems) {
    return `
        <section class="eh-detail-more">
            <h2 class="eh-detail-h2">${creator ? `More by ${escapeHtml(creator)}` : 'Related extensions'}</h2>
            <div class="eh-detail-more-list">
                ${relatedItems.map((related) => renderRelatedRow(related)).join('')}
            </div>
        </section>
    `;
}

function renderRelatedRow(item) {
    const rating = readMetric(item, 'ego', 'rating');
    const comments = readMetric(item, 'ego', 'comments');
    const hasRating = rating !== null && rating > 0;

    return `
        <article class="eh-row">
            <a class="eh-row-link"
               href="${escapeHtmlAttr(getDetailPath(item))}"
               data-related-uuid="${escapeHtmlAttr(item.uuid)}"
               aria-label="View ${escapeHtmlAttr(item.name)}"></a>

            ${renderRelatedIcon(item)}

            <div class="eh-row-text">
                <h3 class="eh-row-name">
                    <span class="eh-row-name-text" title="${escapeHtmlAttr(item.name)}">${escapeHtml(item.name)}</span>
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
                           ${comments !== null ? `<span class="eh-rating-comments">(${formatCount(comments)})</span>` : ''}`
                        : ''}
                </div>
            </div>
        </article>
    `;
}

function renderRelatedIcon(item) {
    const icon = resolveAssetUrl(getIconUrl(item));
    const isGeneric = !icon || icon.includes('/static/') || icon.includes('plugin.png');

    if (!isGeneric) {
        return `<span class="eh-row-icon" aria-hidden="true"><img src="${escapeHtmlAttr(icon)}" alt="" loading="lazy"></span>`;
    }

    const letter = escapeHtml((item.name || '?').trim().charAt(0).toUpperCase());
    const screenshot = resolveAssetUrl(getScreenshotUrl(item));

    if (screenshot) {
        return `<span class="eh-row-icon eh-row-icon-shot" aria-hidden="true"><img src="${escapeHtmlAttr(screenshot)}" alt="" loading="lazy"><span class="eh-row-icon-shot-letter">${letter}</span></span>`;
    }

    const color = LETTER_TILE_PALETTE[stableTextHash(item.uuid ?? item.name ?? '') % LETTER_TILE_PALETTE.length];

    return `<span class="eh-row-icon eh-row-icon-letter" style="background-color: ${color}" aria-hidden="true">${letter}</span>`;
}

/* ----------------------------------------------------------------- Facts */

function renderFactsSidebar(item) {
    const facts = collectFacts(item);
    const links = collectLinks(item);

    if (facts.length === 0 && links.length === 0) {
        return '';
    }

    return `
        <aside class="eh-facts" aria-label="Extension facts">
            ${facts.map((fact) => renderFact(fact)).join('')}
            ${links.length > 0
                ? `<div class="eh-facts-links">
                       <span class="eh-facts-links-label">Links</span>
                       ${links.map((link) => `
                           <a class="eh-facts-link" href="${escapeHtmlAttr(link.href)}" target="_blank" rel="noopener noreferrer">
                               ${link.icon}${escapeHtml(link.label)}
                           </a>
                       `).join('')}
                   </div>`
                : ''}
        </aside>
    `;
}

function renderFact(fact) {
    const factValue = fact.allVersions
        ? `<button type="button" class="eh-fact-expand" data-fact-expand data-expanded-value="${escapeHtmlAttr(fact.allVersions.join(', '))}" aria-expanded="false">${escapeHtml(fact.value)}<span aria-hidden="true"> + ${fact.allVersions.length - FACT_SHELL_VERSIONS} more</span></button>`
        : escapeHtml(fact.value);
    const value = fact.href
        ? `<a href="${escapeHtmlAttr(fact.href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(fact.value)}${ICONS.external}</a>`
        : `<span${fact.code ? ' class="eh-fact-code"' : ''}>${fact.icon ?? ''}${factValue}</span>`;

    return `
        <div class="eh-fact">
            <span class="eh-fact-label">${escapeHtml(fact.label)}</span>
            <span class="eh-fact-value">${value}${fact.extra ? `<span class="eh-fact-extra">${escapeHtml(fact.extra)}</span>` : ''}</span>
        </div>
    `;
}

/** Only facts backed by real snapshot values become rows. */
function collectFacts(item) {
    const facts = [];

    if (item.creator) {
        facts.push({ label: 'Author', value: item.creator, href: resolveEgoUrl(item.creatorUrl) });
    }

    const shellVersions = getSortedShellVersions(item.supportedShellVersions);
    if (shellVersions.length > 0) {
        facts.push({
            label: 'Shell versions',
            value: shellVersions.slice(0, FACT_SHELL_VERSIONS).join(', '),
            allVersions: shellVersions,
        });
    }

    const downloads = readMetric(item, 'ego', 'downloads');
    if (downloads !== null) {
        facts.push({
            label: 'Downloads',
            value: formatCount(downloads),
            extra: formatWeekDelta(readMetric(item, 'ego', 'downloadsDelta7d')),
        });
    }

    const stars = readMetric(item, 'github', 'stars');
    if (stars !== null) {
        facts.push({
            label: 'Stars',
            value: formatCount(stars),
            extra: formatWeekDelta(readMetric(item, 'github', 'starsDelta7d')),
            icon: `<span class="eh-fact-icons" aria-hidden="true">${ICONS.github}<span>${ICONS.star}</span></span>`,
        });
    }

    const forks = readMetric(item, 'github', 'forks');
    if (forks !== null) {
        facts.push({ label: 'Forks (GitHub)', value: formatCount(forks) });
    }

    const published = formatDate(item.createdAt);
    if (published) {
        facts.push({ label: 'Published', value: published });
    }

    const updated = formatDate(item.updatedAt);
    if (updated) {
        facts.push({ label: 'Last updated', value: updated });
    }

    if (item.uuid) {
        facts.push({ label: 'UUID', value: item.uuid, code: true });
    }

    return facts;
}

/** The public snapshot contract only carries EGO and GitHub links. */
function collectLinks(item) {
    const links = [];
    const ego = getSource(item, 'ego')?.links ?? {};
    const github = getSource(item, 'github')?.links ?? {};

    if (ego.pageUrl) {
        links.push({ label: 'extensions.gnome.org', href: ego.pageUrl, icon: ICONS.puzzle });
    }
    if (github.repositoryUrl) {
        links.push({ label: 'GitHub repository', href: github.repositoryUrl, icon: ICONS.github });
    }
    if (github.releaseUrl) {
        links.push({ label: 'Latest release', href: github.releaseUrl, icon: ICONS.tag });
    }

    return links;
}

function getSortedShellVersions(versions) {
    if (!Array.isArray(versions)) {
        return [];
    }

    return versions
        .map((version) => String(version).trim())
        .filter((version) => version !== '')
        .sort((a, b) => compareShellVersions(b, a));
}

function formatWeekDelta(delta) {
    if (delta === null) {
        return '';
    }

    return `${delta < 0 ? '' : '+'}${formatCount(delta)} this week`;
}

/* --------------------------------------------------------------- Reviews */

/**
 * Render the loaded reviews into the detail page's reviews section.
 *
 * All reviews are already in memory, so the reveal button is pure display
 * state: everything past the first five is rendered but hidden, and one click
 * unhides the rest without a further request.
 *
 * @param {Element} section - The `[data-comments-section]` element
 * @param {Array} comments - Comments for this extension (may be empty)
 */
export function renderCommentsSection(section, comments) {
    if (comments.length === 0) {
        section.innerHTML = `
            <h2 class="eh-detail-h2">Reviews</h2>
            <p class="eh-review-empty">No reviews yet. Be the first to review this extension on extensions.gnome.org.</p>
        `;
        return;
    }

    const hiddenCount = Math.max(0, comments.length - INITIAL_VISIBLE_REVIEWS);

    section.innerHTML = `
        <h2 class="eh-detail-h2">Reviews (${formatCount(comments.length)})</h2>
        <div class="eh-detail-reviews-list">
            ${comments.map((comment, index) => renderSingleComment(comment, index >= INITIAL_VISIBLE_REVIEWS)).join('')}
        </div>
        ${hiddenCount > 0
            ? `<button type="button" class="eh-review-more" data-reveal-reviews>
                   Show ${formatCount(hiddenCount)} more reviews${ICONS.chevronDown}
               </button>`
            : ''}
    `;

    const revealButton = section.querySelector('[data-reveal-reviews]');
    revealButton?.addEventListener('click', () => {
        section.querySelectorAll('.eh-review.is-hidden').forEach((review) => {
            review.classList.remove('is-hidden');
        });
        revealButton.remove();
    });
}

const ALLOWED_COMMENT_TAGS = new Set(['A', 'B', 'BLOCKQUOTE', 'BR', 'CODE', 'EM', 'I', 'LI', 'OL', 'P', 'PRE', 'STRONG', 'UL']);
const DROP_COMMENT_TAGS = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'LINK', 'META']);

/**
 * EGO delivers comment bodies as HTML. Reduce them to a small tag allowlist
 * before rendering so external content cannot inject scripts or handlers.
 */
function sanitizeCommentHtml(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    sanitizeCommentNodes(doc.body);
    return doc.body.innerHTML;
}

function sanitizeCommentNodes(root) {
    for (const node of [...root.childNodes]) {
        if (node.nodeType !== Node.ELEMENT_NODE) {
            continue;
        }

        sanitizeCommentNodes(node);

        if (DROP_COMMENT_TAGS.has(node.tagName)) {
            node.remove();
            continue;
        }

        if (!ALLOWED_COMMENT_TAGS.has(node.tagName)) {
            node.replaceWith(...node.childNodes);
            continue;
        }

        if (node.tagName === 'A') {
            const href = node.getAttribute('href') ?? '';
            for (const attr of [...node.attributes]) {
                node.removeAttribute(attr.name);
            }
            if (/^(https?:|mailto:)/i.test(href)) {
                node.setAttribute('href', href);
            }
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener noreferrer nofollow');
            continue;
        }

        for (const attr of [...node.attributes]) {
            node.removeAttribute(attr.name);
        }
    }
}

function renderSingleComment(comment, isHidden) {
    const author = comment.author || 'Anonymous';
    const rating = Number(comment.rating ?? 0);
    const date = formatDate(comment.date);
    const body = comment.comment ? sanitizeCommentHtml(comment.comment) : '';

    return `
        <article class="eh-review${isHidden ? ' is-hidden' : ''}">
            ${renderReviewAvatar(comment, author)}
            <div class="eh-review-body">
                <div class="eh-review-head">
                    <span class="eh-review-author">${escapeHtml(author)}</span>
                    ${comment.isCreator === true ? '<span class="eh-review-dev">Developer</span>' : ''}
                    ${date ? `<span class="eh-review-date">${escapeHtml(date)}</span>` : ''}
                </div>
                ${rating > 0
                    ? `<span class="eh-stars" role="img" aria-label="Rated ${rating.toFixed(1)} of 5">
                           <span class="eh-stars-dim" aria-hidden="true"></span>
                           <span class="eh-stars-fill" style="width: ${(rating / 5) * 100}%" aria-hidden="true"></span>
                       </span>`
                    : ''}
                ${body ? `<div class="eh-review-text">${body}</div>` : ''}
            </div>
        </article>
    `;
}

function renderReviewAvatar(comment, author) {
    if (comment.gravatar) {
        return `<span class="eh-review-avatar" aria-hidden="true"><img src="${escapeHtmlAttr(comment.gravatar)}" alt="" loading="lazy"></span>`;
    }

    const color = LETTER_TILE_PALETTE[stableTextHash(author) % LETTER_TILE_PALETTE.length];

    return `<span class="eh-review-avatar" style="background-color: ${color}" aria-hidden="true">${escapeHtml(author.trim().charAt(0).toUpperCase() || '?')}</span>`;
}

/* --------------------------------------------------------------- Helpers */

/**
 * Source metrics are presence-based in snapshot v2: an unmeasured metric is
 * omitted instead of serialized as 0/null. Reading them through field presence
 * keeps a real 0 visible rather than collapsing it into "missing".
 *
 * @returns {number|null} The measured value, or null when unmeasured
 */
function readMetric(item, sourceType, metricKey) {
    const metrics = getSource(item, sourceType)?.metrics;
    if (!metrics || !Object.prototype.hasOwnProperty.call(metrics, metricKey)) {
        return null;
    }

    const value = Number(metrics[metricKey]);

    return Number.isFinite(value) ? value : null;
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

/** `creatorUrl` arrives as an EGO-relative profile path or null. */
function resolveEgoUrl(path) {
    if (!path) {
        return null;
    }

    if (path.startsWith('http://') || path.startsWith('https://')) {
        return path;
    }

    return `https://extensions.gnome.org${path.startsWith('/') ? path : `/${path}`}`;
}

/** Unparseable dates render nothing instead of an "Unknown" placeholder. */
function formatDate(isoDate) {
    const parsed = Date.parse(isoDate);
    if (Number.isNaN(parsed)) {
        return '';
    }

    return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(new Date(parsed));
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

/** Locale-independent count formatting: English comma grouping everywhere. */
function formatCount(num) {
    return Number(num ?? 0).toLocaleString('en-US');
}

/** Deterministic palette index; v2 has no numeric pk to index by anymore. */
function stableTextHash(text) {
    let hash = 0;
    for (let i = 0; i < String(text).length; i++) {
        hash = (hash * 31 + String(text).charCodeAt(i)) | 0;
    }
    return Math.abs(hash);
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

function escapeCssUrl(url) {
    return encodeURI(String(url ?? ''))
        .replaceAll('"', '%22')
        .replaceAll("'", '%27')
        .replaceAll('(', '%28')
        .replaceAll(')', '%29');
}
