/**
 * Best-effort extraction of numeric proposal id from assistant reply text when the model mentions it.
 * Authoritative data comes from GET /api/purchase-order-proposals/{id}.
 *
 * @param {string|null|undefined} text
 * @returns {string|null}
 */
export function extractProposalIdFromAssistantText(text) {
    if (text === null || text === undefined) {
        return null;
    }
    const s = String(text);
    const patterns = [
        /proposal\s*(?:id|#)?\s*[:is#\s-]+(\d{1,12})/i,
        /pending\s+proposal[^0-9]{0,40}(\d{1,12})/i,
        /#(\d{1,12})\b[^\n]{0,20}proposal/i,
    ];
    for (const p of patterns) {
        const m = s.match(p);
        if (m) {
            return m[1];
        }
    }
    return null;
}
