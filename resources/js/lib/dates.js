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
