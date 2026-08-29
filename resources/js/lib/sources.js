/**
 * Display names for the scraper adapter keys stored on `event.source`.
 *
 * Keys mirror `adapter_registry` in config/eventpulse.php. An unknown key falls
 * through to `sourceLabel()`'s raw value rather than being hidden, so a newly
 * added adapter shows up as something rather than nothing.
 */
export const SOURCE_LABELS = {
    iabilet: 'iaBilet',
    zilesinopti: 'Zile și Nopți',
    allevents: 'AllEvents',
    eventbrite: 'Eventbrite',
    onevent: 'OnEvent',
    entertix: 'Entertix',
    meetup: 'Meetup',
    google_events: 'Google Events',
    timisoreni: 'Timisoreni',
    opera_timisoara: 'Opera Timișoara',
    teatru_national_tm: 'Teatrul Național TM',
    visit_timisoara: 'Visit Timișoara',
    radio_timisoara: 'Radio Timișoara',
};

/**
 * @param {string|null|undefined} source
 * @returns {string|null}
 */
export function sourceLabel(source) {
    if (!source) {
        return null;
    }

    return SOURCE_LABELS[source] ?? source;
}
