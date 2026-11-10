/**
 * @param {number} ms
 */
export function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

export class AssistantRunPollError extends Error {
    /**
     * @param {'unauthenticated'|'not_found'|'timeout'} code
     * @param {string} message
     */
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

/**
 * @param {string|null|undefined} storedUserId
 * @param {string|null|undefined} currentUserId
 * @param {string|null|undefined} runId
 */
/**
 * @param {string} text
 * @param {number} [maxLen]
 */
export function truncatePendingRunMessage(text, maxLen = 4000) {
    if (typeof text !== 'string') {
        return '';
    }
    const trimmed = text.trim();
    if (trimmed === '') {
        return '';
    }
    return trimmed.length > maxLen ? trimmed.slice(0, maxLen) : trimmed;
}

export function shouldResumePendingRun(storedUserId, currentUserId, runId) {
    if (typeof runId !== 'string' || runId.trim() === '') {
        return false;
    }
    if (typeof storedUserId !== 'string' || storedUserId.trim() === '') {
        return false;
    }
    if (typeof currentUserId !== 'string' && typeof currentUserId !== 'number') {
        return false;
    }
    return String(storedUserId) === String(currentUserId);
}

/**
 * @param {number} httpStatus
 * @returns {'ok'|'unauthenticated'|'not_found'|'retry'}
 */
export function classifyAssistantRunPollHttpStatus(httpStatus) {
    if (httpStatus === 401) {
        return 'unauthenticated';
    }
    if (httpStatus === 404) {
        return 'not_found';
    }
    if (httpStatus === 200) {
        return 'ok';
    }
    return 'retry';
}

/**
 * Poll assistant run status until terminal state or timeout.
 *
 * @param {(runId: string) => Promise<{ status: number, data: unknown }>} fetchRun
 * @param {string} runId
 * @param {{ onStatus?: (status: string) => void, maxDurationMs?: number }} options
 */
export async function pollAssistantRunUntilDone(fetchRun, runId, options = {}) {
    const maxDurationMs = options.maxDurationMs ?? 30 * 60 * 1000;
    const started = Date.now();
    let intervalMs = 2000;

    while (Date.now() - started < maxDurationMs) {
        const res = await fetchRun(runId);
        const pollKind = classifyAssistantRunPollHttpStatus(res.status);
        if (pollKind === 'unauthenticated') {
            throw new AssistantRunPollError('unauthenticated', 'Session expired — sign in again.');
        }
        if (pollKind === 'not_found') {
            throw new AssistantRunPollError('not_found', 'This assistant request is no longer available.');
        }

        const body = res.data;
        const data =
            body && typeof body === 'object' && 'data' in body && body.data && typeof body.data === 'object'
                ? body.data
                : body;
        const status =
            data && typeof data === 'object' && 'status' in data && typeof data.status === 'string'
                ? data.status
                : '';

        if (options.onStatus && status) {
            options.onStatus(status);
        }

        if (status === 'completed' || status === 'failed') {
            return { response: res, terminalData: data };
        }

        if (pollKind === 'retry') {
            await sleep(intervalMs);
            intervalMs = Math.min(Math.round(intervalMs * 1.25), 8000);
            continue;
        }

        await sleep(intervalMs);
        intervalMs = Math.min(Math.round(intervalMs * 1.25), 8000);
    }

    throw new AssistantRunPollError('timeout', 'Assistant run timed out while waiting for completion.');
}

/**
 * @param {number} httpStatus
 * @param {unknown} payload
 */
export function isAsyncAssistantAccepted(httpStatus, payload) {
    if (httpStatus !== 202) {
        return false;
    }
    const root = payload && typeof payload === 'object' ? payload : null;
    const data = root && 'data' in root && root.data && typeof root.data === 'object' ? root.data : null;
    return Boolean(data && data.async === true && typeof data.run_id === 'string');
}
