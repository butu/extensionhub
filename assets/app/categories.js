/**
 * Category Heuristics Module
 *
 * The snapshot has no category data, so sidebar categories are derived
 * client-side from name/description/uuid keywords. Keyword matches use
 * padded word boundaries so e.g. "file" does not match "profile".
 * "github" is a pseudo category matching extensions with a GitHub source
 * (dual-sourced extensions count in both filters).
 */

// Sidebar order follows expected demand, from broad shell customization to specialist integrations.
export const CATEGORY_DEFS = [
    { key: 'top-bar', label: 'Top Bar & Indicators', keywords: ['top bar', 'top bars', 'topbar', 'panel', 'panels', 'clock', 'clocks', 'calendar', 'calendars', 'tray', 'system tray', 'status area', 'indicator', 'indicators', 'appindicator'] },
    { key: 'launchers', label: 'Docks, Menus & Launchers', keywords: ['dock', 'docks', 'dash', 'menu', 'menus', 'launcher', 'launchers', 'app grid', 'overview', 'application menu', 'start menu'] },
    { key: 'windows', label: 'Windows & Workspaces', keywords: ['window', 'windows', 'titlebar', 'titlebars', 'title bar', 'workspace', 'workspaces', 'tiling', 'snap', 'minimize', 'maximize', 'hot corner', 'hot corners', 'window switcher', 'multimonitor', 'multi monitor'] },
    { key: 'appearance', label: 'Appearance & Themes', keywords: ['theme', 'themes', 'wallpaper', 'wallpapers', 'cursor', 'cursors', 'icon', 'icons', 'font', 'fonts', 'accent', 'gtk', 'background', 'backgrounds', 'blur', 'transparent', 'transparency', 'rounded corner', 'rounded corners', 'animation', 'animations'] },
    { key: 'system', label: 'System & Power', keywords: ['battery', 'batteries', 'power', 'cpu', 'gpu', 'memory', 'ram', 'temperature', 'fan', 'fans', 'system monitor', 'resource monitor', 'performance', 'process', 'processes', 'sensor', 'sensors'] },
    { key: 'productivity', label: 'Productivity Tools', keywords: ['nautilus', 'file', 'files', 'folder', 'folders', 'clipboard', 'paste', 'download', 'downloads', 'notification', 'notifications', 'do not disturb', 'screenshot', 'screenshots', 'screen recording', 'shortcut', 'shortcuts', 'timer', 'timers', 'pomodoro', 'todo', 'note', 'notes', 'terminal'] },
    { key: 'media', label: 'Audio, Media & Display', keywords: ['sound', 'sounds', 'audio', 'volume', 'media', 'music', 'player', 'players', 'equalizer', 'microphone', 'brightness', 'display', 'night light', 'color temperature'] },
    { key: 'devices', label: 'Devices & Connectivity', keywords: ['network', 'networks', 'netspeed', 'wifi', 'wi fi', 'vpn', 'bluetooth', 'device', 'devices', 'phone', 'phones', 'mobile', 'android', 'gsconnect', 'kde connect', 'browser', 'cloud', 'sync', 'remote', 'printer', 'printers', 'webcam'] },
];

export const GITHUB_CATEGORY_KEY = 'github';

function searchableText(item) {
    return ` ${item.name} ${item.description ?? ''} ${item.uuid ?? ''} `
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ');
}

export function matchesCategory(item, key) {
    if (key === GITHUB_CATEGORY_KEY) {
        return (item.sources ?? []).some((source) => source.sourceType === 'github');
    }

    const def = CATEGORY_DEFS.find((entry) => entry.key === key);
    if (!def) {
        return false;
    }

    const text = searchableText(item);
    return def.keywords.some((keyword) => text.includes(` ${keyword} `));
}

export function categoryLabel(key) {
    if (key === GITHUB_CATEGORY_KEY) {
        return 'GitHub';
    }

    return CATEGORY_DEFS.find((entry) => entry.key === key)?.label ?? key;
}

export function countCategory(items, key) {
    return items.reduce((count, item) => (matchesCategory(item, key) ? count + 1 : count), 0);
}
