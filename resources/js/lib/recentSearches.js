/**
 * The visitor's own recent event searches, kept in localStorage.
 *
 * Per-browser and never sent anywhere: this is a convenience for the person
 * typing, not an analytics signal. The server already records settled searches
 * separately, and only for requests the user committed to.
 *
 * Every access is wrapped: `localStorage` throws outright in Safari's private
 * mode and when a browser is set to block site data, and a search box that
 * crashes the page is a far worse failure than one with no history.
 */

const STORAGE_KEY = 'ghes.recentSearches';
const MAX_ENTRIES = 5;

/**
 * The stored searches, most recent first. Always an array.
 *
 * @returns {string[]}
 */
export function readRecentSearches() {
    let raw;

    try {
        raw = window.localStorage.getItem(STORAGE_KEY);
    } catch {
        // The store itself is unreachable — a private window, or a browser set
        // to block site data. Nothing to repair and nothing to report.
        return [];
    }

    try {
        const parsed = JSON.parse(raw || '[]');

        // Anything could be under this key — another tab, an older build, a
        // user poking at devtools. Only strings are usable as a search term.
        return Array.isArray(parsed) ? parsed.filter((entry) => typeof entry === 'string') : [];
    } catch {
        // Corrupt value. Unlike an unreachable store this is repairable, and
        // without repairing it every future read throws for the life of the
        // browser — the history would look merely empty while being broken,
        // and the user has no reason to press "Șterge" to fix a list that
        // appears to have nothing in it.
        clearRecentSearches();

        return [];
    }
}

/**
 * Record a search the user actually committed to, moving a repeat to the front
 * rather than duplicating it.
 *
 * @param {string} term
 * @returns {string[]} the updated list
 */
export function pushRecentSearch(term) {
    const trimmed = (term || '').trim();

    if (!trimmed) {
        return readRecentSearches();
    }

    const existing = readRecentSearches().filter(
        (entry) => entry.toLowerCase() !== trimmed.toLowerCase()
    );
    const updated = [trimmed, ...existing].slice(0, MAX_ENTRIES);

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(updated));
    } catch {
        // Full or unavailable storage costs the user their history, nothing
        // more — but the caller renders what comes back, so returning `updated`
        // here would show the term under "Căutări recente" as though it had
        // been saved, only for it to vanish on the next load. Report what is
        // actually stored instead.
        return readRecentSearches();
    }

    return updated;
}

/**
 * Forget every stored search.
 *
 * @returns {string[]}
 */
export function clearRecentSearches() {
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing to clean up if the store was never reachable.
    }

    return [];
}
