/**
 * Snapshot Loader Module
 *
 * Fetches and validates the extensions snapshot (schema v2) and exposes
 * small contract helpers for reading source-scoped links/metrics.
 */

export class SnapshotLoadError extends Error {
    constructor(message) {
        super(message);
        this.name = 'SNAPSHOT_LOAD_ERROR';
    }
}

export class SnapshotValidationError extends Error {
    constructor(message) {
        super(message);
        this.name = 'SNAPSHOT_VALIDATION_ERROR';
    }
}

/**
 * Load and validate the extensions snapshot.
 *
 * @param {string} feedUrl - URL to fetch the snapshot from
 * @param {Object} options - Fetch options (e.g., { cache: 'no-cache' })
 * @returns {Promise<Object>} The validated snapshot payload
 * @throws {SnapshotLoadError} if fetch fails
 * @throws {SnapshotValidationError} if snapshot is invalid
 */
export async function loadSnapshot(feedUrl, options = {}) {
    try {
        const response = await fetch(feedUrl, options);
        if (!response.ok) {
            throw new SnapshotLoadError(`HTTP ${response.status}: ${response.statusText}`);
        }

        const payload = await response.json();
        validateSnapshot(payload);
        return payload;
    } catch (error) {
        if (error instanceof SnapshotValidationError) {
            throw error;
        }
        if (error instanceof SnapshotLoadError) {
            throw error;
        }
        throw new SnapshotLoadError(`Failed to load snapshot: ${error.message}`);
    }
}

/* ------------------------------------------------------- Contract helpers */

/**
 * Get the source entry of a given type ('ego' | 'github') from an item.
 *
 * @param {Object} item - Snapshot v2 item
 * @param {string} sourceType
 * @returns {Object|null} Source entry or null when the item has no such source
 */
export function getSource(item, sourceType) {
    return (item.sources ?? []).find((source) => source.sourceType === sourceType) ?? null;
}

/**
 * Get a source-scoped metric ('downloads', 'rating', 'comments' on ego;
 * 'stars', 'forks' on github). Returns undefined when unmeasured — v2
 * omits metrics instead of serializing 0/null.
 *
 * @param {Object} item - Snapshot v2 item
 * @param {string} sourceType
 * @param {string} metricKey
 * @returns {number|undefined}
 */
export function getMetric(item, sourceType, metricKey) {
    return getSource(item, sourceType)?.metrics?.[metricKey];
}

/**
 * Primary row/detail action: EGO install beats GitHub release download
 * beats GitHub repository link.
 *
 * @param {Object} item - Snapshot v2 item
 * @returns {{label: string, url: string}|null}
 */
export function getPrimaryAction(item) {
    const ego = getSource(item, 'ego');
    if (ego?.links?.installUrl) {
        return { label: 'Install', url: ego.links.installUrl };
    }

    const github = getSource(item, 'github');
    if (github?.links?.releaseUrl) {
        return { label: 'Download', url: github.links.releaseUrl };
    }
    if (github?.links?.repositoryUrl) {
        return { label: 'Repository', url: github.links.repositoryUrl };
    }

    return null;
}

/**
 * Canonical detail path for an item. `path` is pre-encoded by the
 * snapshot builder, so it is used as-is when present.
 *
 * @param {Object} item - Snapshot v2 item
 * @returns {string}
 */
export function getDetailPath(item) {
    return item.path || `/extension/${encodeURIComponent(item.uuid)}`;
}

/** First non-empty displayIcon across the item's sources (absolute or EGO-relative). */
export function getIconUrl(item) {
    for (const source of item.sources ?? []) {
        if (source.displayIcon) {
            return source.displayIcon;
        }
    }
    return null;
}

/** First non-empty displayScreenshot across the item's sources. */
export function getScreenshotUrl(item) {
    for (const source of item.sources ?? []) {
        if (source.displayScreenshot) {
            return source.displayScreenshot;
        }
    }
    return null;
}

/* ------------------------------------------------------------ Validation */

const EGO_METRIC_KEYS = new Set([
    'downloads', 'rating', 'comments',
    'downloadsDelta1d', 'downloadsDelta7d', 'downloadsDelta30d',
]);
const GITHUB_METRIC_KEYS = new Set([
    'stars', 'forks',
    'starsDelta1d', 'starsDelta7d', 'starsDelta30d',
]);
const SOURCE_TYPES = new Set(['ego', 'github']);

/**
 * Validate the snapshot against the v2 schema.
 *
 * @param {Object} payload - The snapshot payload
 * @throws {SnapshotValidationError} if validation fails
 */
function validateSnapshot(payload) {
    // Verify schema version
    if (payload.schemaVersion !== 2) {
        throw new SnapshotValidationError(`Unsupported schema version: ${payload.schemaVersion}`);
    }

    // Verify count matches items length
    if (payload.count !== (payload.items?.length ?? 0)) {
        throw new SnapshotValidationError(
            `Count mismatch: count=${payload.count}, items.length=${payload.items?.length ?? 0}`
        );
    }

    // Verify required top-level fields
    const requiredTopLevelFields = ['schemaVersion', 'generatedAt', 'count', 'pageSize', 'items'];
    for (const field of requiredTopLevelFields) {
        if (!(field in payload)) {
            throw new SnapshotValidationError(`Missing top-level field: ${field}`);
        }
    }

    // Verify pageSize is 20
    if (payload.pageSize !== 20) {
        throw new SnapshotValidationError(`Invalid pageSize: expected 20, got ${payload.pageSize}`);
    }

    // Verify uniqueness of uuid, path
    const uuids = new Set();
    const paths = new Set();

    for (let i = 0; i < payload.items.length; i++) {
        const item = payload.items[i];

        const requiredItemFields = [
            'uuid', 'path', 'name', 'description', 'creator', 'creatorUrl',
            'supportedShellVersions', 'createdAt', 'updatedAt', 'recentSortValue',
            'score', 'scoreComponents', 'sources', 'hasScreenshot', 'trendScore',
        ];
        for (const field of requiredItemFields) {
            if (!(field in item)) {
                throw new SnapshotValidationError(`Item ${i} missing required field: ${field}`);
            }
        }

        // creatorUrl is the only field the contract allows to be null.
        if (item.creatorUrl !== null && typeof item.creatorUrl !== 'string') {
            throw new SnapshotValidationError(`Item ${i} has invalid creatorUrl`);
        }

        if (!Array.isArray(item.supportedShellVersions)) {
            throw new SnapshotValidationError(`Item ${i} has invalid supportedShellVersions`);
        }

        if (!Number.isInteger(item.score) || item.score < 0 || item.score > 100) {
            throw new SnapshotValidationError(`Item ${i} has invalid score`);
        }

        if (!Number.isInteger(item.trendScore) || item.trendScore < 0 || item.trendScore > 100) {
            throw new SnapshotValidationError(`Item ${i} has invalid trendScore`);
        }

        if (!Array.isArray(item.sources) || item.sources.length === 0) {
            throw new SnapshotValidationError(`Item ${i} has no sources`);
        }

        item.sources.forEach((source, sourceIndex) => {
            if (!SOURCE_TYPES.has(source.sourceType)) {
                throw new SnapshotValidationError(`Item ${i} source ${sourceIndex} has invalid sourceType: ${source.sourceType}`);
            }

            const allowedMetrics = source.sourceType === 'ego' ? EGO_METRIC_KEYS : GITHUB_METRIC_KEYS;
            for (const key of Object.keys(source.metrics ?? {})) {
                if (!allowedMetrics.has(key)) {
                    throw new SnapshotValidationError(`Item ${i} source ${sourceIndex} has unexpected metric: ${key}`);
                }
            }

            if (source.sourceType === 'ego' && !(source.links?.pageUrl && source.links?.installUrl)) {
                throw new SnapshotValidationError(`Item ${i} ego source ${sourceIndex} misses pageUrl/installUrl`);
            }
            if (source.sourceType === 'github' && !source.links?.repositoryUrl) {
                throw new SnapshotValidationError(`Item ${i} github source ${sourceIndex} misses repositoryUrl`);
            }
        });

        // Check uniqueness
        if (uuids.has(item.uuid)) {
            throw new SnapshotValidationError(`Duplicate uuid: ${item.uuid}`);
        }
        uuids.add(item.uuid);

        if (paths.has(item.path)) {
            throw new SnapshotValidationError(`Duplicate path: ${item.path}`);
        }
        paths.add(item.path);
    }
}
