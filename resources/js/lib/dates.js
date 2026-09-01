/**
 * Format an event's start for a dense admin table.
 *
 * Several adapters publish date-only events as local midnight, so a 00:00
 * clock time is shown as a bare date rather than as a real start time.
 *
 * @param {string|null|undefined} iso
 * @returns {string}
 */
export function formatEventDate(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const day = date.toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

    if (date.getHours() === 0 && date.getMinutes() === 0) {
        return day;
    }

    const time = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

    return `${day}, ${time}`;
}

/**
 * Format a scraper run's timestamp, which always carries a meaningful clock
 * time — unlike an event start, a run at midnight really did happen at midnight.
 *
 * @param {string|null|undefined} iso
 * @returns {string}
 */
export function formatRunTime(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * A run's wall-clock duration, or an em dash while it is still running.
 *
 * @param {string|null|undefined} startedAt
 * @param {string|null|undefined} finishedAt
 * @returns {string}
 */
export function formatDuration(startedAt, finishedAt) {
    if (!startedAt || !finishedAt) {
        return '—';
    }

    const seconds = Math.round((new Date(finishedAt) - new Date(startedAt)) / 1000);

    if (Number.isNaN(seconds) || seconds < 0) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    return minutes < 60
        ? `${minutes}m ${seconds % 60}s`
        : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

/**
 * A day-and-month stamp for activity lists, where the exact minute is noise —
 * knowing you reacted to something on 3 sep. is the useful part, not 14:32.
 *
 * @param {string|null|undefined} iso
 * @returns {string}
 */
export function formatDayMonth(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
