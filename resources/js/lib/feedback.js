/**
 * Romanian message for a failed feedback write, by HTTP status.
 *
 * @param {number} status 0 means the request never reached the server.
 * @returns {string}
 */
function messageForStatus(status) {
    if (status === 0) {
        return 'Fără conexiune. Încearcă din nou.';
    }

    if (status === 401 || status === 419) {
        return 'Sesiunea a expirat. Reîncarcă pagina.';
    }

    return 'Nu am putut salva. Încearcă din nou.';
}

/**
 * Send a feedback/bookmark mutation.
 *
 * Returns a result rather than a bare boolean: an optimistic rollback with no
 * explanation is indistinguishable from a UI glitch, so callers need to know
 * *why* it failed to tell the user anything useful. Failures are also logged —
 * an expired session produces no server-side signal anyone would correlate.
 *
 * @param {string} url
 * @param {'POST'|'DELETE'} method
 * @param {Object} body
 * @returns {Promise<{ok: boolean, status: number, message?: string}>}
 */
export async function sendFeedback(url, method, body) {
    try {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '',
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            console.error('feedback: request rejected', {
                url,
                method,
                status: response.status,
            });

            return {
                ok: false,
                status: response.status,
                message: messageForStatus(response.status),
            };
        }

        return { ok: true, status: response.status };
    } catch (error) {
        console.error('feedback: request failed', { url, method, error });

        return { ok: false, status: 0, message: messageForStatus(0) };
    }
}
