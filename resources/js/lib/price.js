/**
 * Format an event's price for display.
 *
 * Shared by the event card and the event detail page so the two cannot drift.
 * Most scraped events publish no price at all, and a bare `0` is meaningless
 * without the `is_free` flag to confirm it — so anything unpriced returns null
 * and the caller omits the line entirely rather than showing "0 RON".
 *
 * @param {Object} event
 * @param {boolean} [event.is_free]
 * @param {number|null} [event.price_min]
 * @param {number|null} [event.price_max]
 * @param {string} [event.currency]
 * @returns {string|null}
 */
export function formatPrice(event) {
    if (event.is_free) {
        return 'Gratuit';
    }

    const currency = event.currency || 'RON';
    const max = event.price_max;

    // A zero floor that `is_free` does not confirm is not a price. An admin can
    // type 0 without ticking "free" (the two fields are validated
    // independently), and parsePrice() returns 0.0 for "Gratuit" in adapters
    // that leave isFree null — both would otherwise read "De la 0 RON".
    const min =
        event.price_min === 0 && !event.is_free ? null : event.price_min;

    if (min == null) {
        // Some sources publish a ceiling without a floor (Eventbrite parses the
        // two independently). Showing nothing would hide a price we do hold.
        return max ? `Până la ${formatAmount(max)} ${currency}` : null;
    }

    if (max != null && max > min) {
        return `${formatAmount(min)}–${formatAmount(max)} ${currency}`;
    }

    return `De la ${formatAmount(min)} ${currency}`;
}

/**
 * Romanian decimal separator, and no trailing `.00` — scraped prices are whole
 * lei far more often than not, and "De la 50 RON" reads better than
 * "De la 50,00 RON".
 *
 * @param {number} amount
 * @returns {string}
 */
function formatAmount(amount) {
    return amount.toLocaleString('ro-RO', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}
