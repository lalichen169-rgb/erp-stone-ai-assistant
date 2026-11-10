import {
    cancelProposal,
    confirmProposal,
    createApiClient,
    fetchAssistantRun,
    fetchCurrentUser,
    fetchProposal,
    fetchProposalList,
    login,
    revokeToken,
    sendAssistantMessage,
} from './api.js';
import {
    AssistantRunPollError,
    isAsyncAssistantAccepted,
    pollAssistantRunUntilDone,
    shouldResumePendingRun,
} from './assistantPolling.js';
import { extractProposalIdFromAssistantText } from './parsers.js';

const TOKEN_KEY = 'stoneerp_chat_token';
const USER_KEY = 'stoneerp_chat_user';
const CONV_KEY = 'stoneerp_chat_conversation_id';
const RUN_KEY = 'stoneerp_chat_active_run_id';
const RUN_USER_KEY = 'stoneerp_chat_active_run_user_id';
const RUN_MESSAGE_KEY = 'stoneerp_chat_active_run_message';

/** Ignores stale async responses when the user resets the proposal loader or starts a newer load. */
let proposalLoadGeneration = 0;

/** @typedef {{ id: string, kind: 'user'|'assistant'|'system', text: string, isSample?: boolean, assistantPayload?: Record<string, unknown>|null, success?: boolean|null }} ChatMessage */

const state = {
    /** @type {string|null} */
    token: null,
    /** @type {{ id: number, name: string, email: string, role: string }|null} */
    user: null,
    /** @type {string|null} */
    conversationId: null,
    /** @type {ChatMessage[]} */
    messages: [],
    /** @type {string|null} */
    globalError: null,
    sending: false,
    /** @type {{ id: string, key: string, attempt: number }|null} */
    pendingSend: null,
    /** @type {Record<string, unknown>|null} */
    loadedProposal: null,
    proposalLoadError: null,
    proposalActionPending: false,
    /** @type {string|null} */
    hintedProposalId: null,
    /** @type {string} */
    manualProposalId: '',
    /** After "Load another", skip auto-using detected id until user enters or picks one. */
    skipHintOnProposalLoad: false,
    showProposalIdList: false,
    proposalIdListLoading: false,
    /** @type {Array<Record<string, unknown>>|null} */
    proposalIdList: null,
    proposalIdListError: null,
    showDetails: false,
};

const root = document.getElementById('app');
if (!root) {
    throw new Error('#app missing');
}

function loadSession() {
    try {
        state.token = sessionStorage.getItem(TOKEN_KEY);
        const u = sessionStorage.getItem(USER_KEY);
        state.user = u ? JSON.parse(u) : null;
        state.conversationId = sessionStorage.getItem(CONV_KEY);
    } catch {
        state.token = null;
        state.user = null;
        state.conversationId = null;
    }
}

function persistSession() {
    if (state.token) {
        sessionStorage.setItem(TOKEN_KEY, state.token);
    } else {
        sessionStorage.removeItem(TOKEN_KEY);
    }
    if (state.user) {
        sessionStorage.setItem(USER_KEY, JSON.stringify(state.user));
    } else {
        sessionStorage.removeItem(USER_KEY);
    }
    if (state.conversationId) {
        sessionStorage.setItem(CONV_KEY, state.conversationId);
    } else {
        sessionStorage.removeItem(CONV_KEY);
    }
}

function clearSession() {
    state.token = null;
    state.user = null;
    state.conversationId = null;
    state.messages = [];
    state.loadedProposal = null;
    state.hintedProposalId = null;
    state.manualProposalId = '';
    state.showProposalIdList = false;
    state.proposalIdList = null;
    state.proposalIdListError = null;
    sessionStorage.removeItem(TOKEN_KEY);
    sessionStorage.removeItem(USER_KEY);
    sessionStorage.removeItem(CONV_KEY);
    sessionStorage.removeItem(RUN_KEY);
    sessionStorage.removeItem(RUN_USER_KEY);
    sessionStorage.removeItem(RUN_MESSAGE_KEY);
}

function clearPendingRunStorage() {
    sessionStorage.removeItem(RUN_KEY);
    sessionStorage.removeItem(RUN_USER_KEY);
    sessionStorage.removeItem(RUN_MESSAGE_KEY);
}

/**
 * @param {string} runId
 * @param {string} [messageText]
 */
function persistPendingRun(runId, messageText) {
    if (!runId || !state.user?.id) {
        return;
    }
    sessionStorage.setItem(RUN_KEY, runId);
    sessionStorage.setItem(RUN_USER_KEY, String(state.user.id));
    if (typeof messageText === 'string' && messageText.trim() !== '') {
        sessionStorage.setItem(RUN_MESSAGE_KEY, messageText.trim().slice(0, 4000));
    }
}

function reconcilePendingRunStorage() {
    const runId = sessionStorage.getItem(RUN_KEY);
    if (!runId) {
        return;
    }
    const runUser = sessionStorage.getItem(RUN_USER_KEY);
    const currentId = state.user?.id != null ? String(state.user.id) : null;
    if (!shouldResumePendingRun(runUser, currentId, runId)) {
        clearPendingRunStorage();
    }
}

function isPurchasing() {
    return state.user?.role === 'purchasing';
}

/**
 * @param {unknown} payload
 */
function assistantDataFromResponse(payload) {
    if (payload === null || typeof payload !== 'object') {
        return null;
    }
    const p = /** @type {Record<string, unknown>} */ (payload);
    return p.data !== undefined && typeof p.data === 'object' && p.data !== null
        ? /** @type {Record<string, unknown>} */ (p.data)
        : p;
}

/**
 * @param {unknown} err
 */
function errorMessageFromAxios(err) {
    if (err && typeof err === 'object' && 'message' in err && typeof err.message === 'string') {
        return err.message;
    }
    return 'Request failed.';
}

/**
 * @param {import('axios').AxiosResponse} res
 */
function formatApiError(res) {
    const d = res.data;
    if (d && typeof d === 'object') {
        if ('message' in d && typeof d.message === 'string') {
            return d.message;
        }
        if ('error' in d && d.error && typeof d.error === 'object' && 'message' in d.error) {
            return String(d.error.message);
        }
    }
    return `HTTP ${res.status}`;
}

function scrollMessagesToEnd() {
    const el = document.getElementById('chat-scroll');
    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

/** Return focus to the chat composer after send/render (innerHTML recreates the textarea). */
function focusMessageInput() {
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            if (state.sending) {
                return;
            }
            const input = document.getElementById('message-input');
            if (input instanceof HTMLTextAreaElement && !input.disabled) {
                input.focus({ preventScroll: true });
                const end = input.value.length;
                input.setSelectionRange(end, end);
            }
        });
    });
}

async function handleLoginSubmit(ev) {
    ev.preventDefault();
    state.globalError = null;
    const form = /** @type {HTMLFormElement} */ (ev.target);
    const fd = new FormData(form);
    const email = String(fd.get('email') ?? '').trim();
    state._loginEmail = email;
    const password = String(fd.get('password') ?? '');
    const device_name = 'stone-erp-chat-web';

    if (!email || !password) {
        state.globalError = 'Email and password are required.';
        render();
        return;
    }

    const client = createApiClient(null);
    state.sending = true;
    render();

    try {
        const res = await login(client, { email, password, device_name });
        if (res.status !== 200 || !res.data?.token) {
            state.globalError = formatApiError(res);
            state.sending = false;
            render();
            return;
        }
        state.token = res.data.token;
        const api = createApiClient(state.token);
        const me = await fetchCurrentUser(api);
        if (me.status !== 200) {
            state.globalError = me.data?.message ?? 'Could not load user profile.';
            state.token = null;
            state.sending = false;
            render();
            return;
        }
        const payload = me.data?.data !== undefined ? me.data.data : me.data;
        state.user = payload;
        persistSession();
        reconcilePendingRunStorage();
    } catch (e) {
        state.globalError = errorMessageFromAxios(e);
    }
    state.sending = false;
    render();
    if (state.token && state.user) {
        focusMessageInput();
    }
}

async function handleLogout() {
    if (state.token) {
        try {
            await revokeToken(createApiClient(state.token));
        } catch {
            // still clear client state
        }
    }
    clearSession();
    state.globalError = null;
    render();
}

function handleNewConversation() {
    state.conversationId = null;
    state.messages = [];
    state.loadedProposal = null;
    state.hintedProposalId = null;
    state.proposalLoadError = null;
    state.manualProposalId = '';
    state.showProposalIdList = false;
    state.proposalIdList = null;
    state.proposalIdListError = null;
    state.pendingSend = null;
    clearPendingRunStorage();
    persistSession();
    render();
    focusMessageInput();
}

/**
 * @param {string} text
 */
function sanitizeAssistantDisplay(text) {
    return String(text ?? '');
}

/**
 * @param {import('axios').AxiosResponse} res
 * @param {string} messageText
 * @param {string} idemKey
 */
async function finalizeAssistantTurn(res, messageText, idemKey) {
    const data = assistantDataFromResponse(res.data);

    if (res.status === 503 && res.data?.error?.code === 'idempotency_in_progress') {
        await new Promise((r) => setTimeout(r, 800));
        const client = createApiClient(state.token);
        const retry = await sendAssistantMessage(
            client,
            { message: messageText, ...(state.conversationId ? { conversation_id: state.conversationId } : {}) },
            state.conversationId ? idemKey : null,
        );
        return finalizeAssistantTurn(retry, messageText, idemKey);
    }

    if (res.status === 200 && data && typeof data.conversation_id === 'string') {
        state.conversationId = data.conversation_id;
        persistSession();
    }

    const assistantText =
        data && typeof data.message === 'string'
            ? data.message
            : res.status === 200
              ? ''
              : formatApiError(res);

    const completed = data ? data.completed !== false : true;
    const successFlag = res.status === 200 && (data?.success !== false) && completed;

    const msg = {
        id: crypto.randomUUID(),
        kind: /** @type {'assistant'} */ ('assistant'),
        text: sanitizeAssistantDisplay(assistantText),
        success: successFlag,
        assistantPayload: data ?? null,
    };
    state.messages.push(msg);

    if (!successFlag && data) {
        const errLine =
            res.data?.error?.code && res.data?.error?.message
                ? `${res.data.error.code}: ${res.data.error.message}`
                : data.error_code
                  ? String(data.error_code)
                  : null;
        if (errLine) {
            state.messages.push({
                id: crypto.randomUUID(),
                kind: 'system',
                text: errLine,
            });
        }
    }

    if (isPurchasing()) {
        const structuredProposal = data?.purchase_order_proposal;
        const structuredId =
            structuredProposal && typeof structuredProposal === 'object' && structuredProposal.proposal_id
                ? String(structuredProposal.proposal_id)
                : null;
        if (structuredId) {
            state.hintedProposalId = structuredId;
            void handleLoadProposal(structuredId);
        } else {
            const hint = extractProposalIdFromAssistantText(assistantText);
            state.hintedProposalId = hint;
        }
    }

    state.pendingSend = null;
    clearPendingRunStorage();
}

/**
 * @param {string} pendingId
 * @param {unknown} terminalData
 */
function applyTerminalAsyncRun(pendingId, terminalData) {
    const idx = state.messages.findIndex((m) => m.id === pendingId);
    const answer =
        terminalData && typeof terminalData === 'object' && 'answer' in terminalData
            ? String(terminalData.answer ?? '')
            : '';
    const failed =
        terminalData &&
        typeof terminalData === 'object' &&
        'status' in terminalData &&
        terminalData.status === 'failed';
    const errMsg =
        failed && terminalData.error && typeof terminalData.error === 'object'
            ? String(terminalData.error.message ?? 'Request failed.')
            : failed
              ? 'Request failed.'
              : '';

    if (idx >= 0) {
        state.messages[idx] = {
            ...state.messages[idx],
            text: failed ? errMsg : sanitizeAssistantDisplay(answer),
            success: !failed,
            assistantPayload: terminalData ?? null,
        };
    } else if (!failed) {
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'assistant',
            text: sanitizeAssistantDisplay(answer),
            success: true,
            assistantPayload: terminalData ?? null,
        });
    }

    if (failed) {
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'system',
            text: errMsg,
        });
    }

    if (isPurchasing() && !failed) {
        state.hintedProposalId = extractProposalIdFromAssistantText(answer);
    }
}

/**
 * @param {string} runId
 * @param {string} pendingId
 */
async function pollAsyncRunToTerminal(runId, pendingId) {
    const client = createApiClient(state.token);
    try {
        const { terminalData } = await pollAssistantRunUntilDone(
            async (id) => {
                const pollRes = await fetchAssistantRun(client, id);
                return { status: pollRes.status, data: pollRes.data };
            },
            runId,
            {
                onStatus: (status) => {
                    const idx = state.messages.findIndex((m) => m.id === pendingId);
                    if (idx >= 0 && (status === 'queued' || status === 'processing')) {
                        state.messages[idx] = {
                            ...state.messages[idx],
                            assistantPayload: {
                                ...(state.messages[idx].assistantPayload ?? {}),
                                async: true,
                                status,
                                run_id: runId,
                            },
                        };
                    }
                },
            },
        );
        applyTerminalAsyncRun(pendingId, terminalData);
    } catch (e) {
        if (e instanceof AssistantRunPollError && e.code === 'unauthenticated') {
            clearPendingRunStorage();
            clearSession();
            state.globalError = e.message;
            state.pendingSend = null;
            state.sending = false;
            render();
            return;
        }

        const idx = state.messages.findIndex((m) => m.id === pendingId);
        const errText =
            e instanceof AssistantRunPollError ? e.message : errorMessageFromAxios(e);
        if (idx >= 0) {
            state.messages[idx] = {
                ...state.messages[idx],
                text: errText,
                success: false,
            };
        }
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'system',
            text: errText,
        });
    }

    state.pendingSend = null;
    clearPendingRunStorage();
    state.sending = false;
    render();
    scrollMessagesToEnd();
    focusMessageInput();
}

async function tryResumePendingAsyncRun() {
    if (!state.token || !state.user) {
        return;
    }
    reconcilePendingRunStorage();
    const runId = sessionStorage.getItem(RUN_KEY);
    const runUser = sessionStorage.getItem(RUN_USER_KEY);
    if (!shouldResumePendingRun(runUser, state.user.id, runId)) {
        return;
    }
    if (state.sending) {
        return;
    }

    state.sending = true;
    const pendingMessage = sessionStorage.getItem(RUN_MESSAGE_KEY);
    if (pendingMessage) {
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'user',
            text: pendingMessage,
        });
    }
    const pendingId = crypto.randomUUID();
    state.messages.push({
        id: pendingId,
        kind: 'assistant',
        text: 'Resuming your pending request…',
        success: null,
        assistantPayload: { async: true, status: 'processing', run_id: runId },
    });
    render();
    scrollMessagesToEnd();

    await pollAsyncRunToTerminal(runId ?? '', pendingId);
}

/**
 * @param {import('axios').AxiosResponse} res
 * @param {string} messageText
 * @param {string} idemKey
 */
async function handleAssistantResponse(res, messageText, idemKey) {
    if (isAsyncAssistantAccepted(res.status, res.data)) {
        const data = assistantDataFromResponse(res.data);
        const runId = data && typeof data.run_id === 'string' ? data.run_id : null;
        if (runId) {
            persistPendingRun(runId, messageText);
        }
        if (data && typeof data.conversation_id === 'string') {
            state.conversationId = data.conversation_id;
            persistSession();
        }

        const pendingId = crypto.randomUUID();
        state.messages.push({
            id: pendingId,
            kind: 'assistant',
            text: 'Processing your request…',
            success: null,
            assistantPayload: { async: true, status: data?.status ?? 'queued', run_id: runId },
        });
        render();
        scrollMessagesToEnd();

        await pollAsyncRunToTerminal(runId ?? '', pendingId);
        return;
    }

    await finalizeAssistantTurn(res, messageText, idemKey);
}

async function handleSend(ev) {
    if (ev) {
        ev.preventDefault();
    }
    if (!state.token || state.sending) {
        return;
    }

    const input = /** @type {HTMLTextAreaElement|null} */ (document.getElementById('message-input'));
    const text = input?.value?.trim() ?? '';
    if (!text) {
        return;
    }

    state.globalError = null;
    state.sending = true;
    const idemKey = crypto.randomUUID();
    state.pendingSend = { id: crypto.randomUUID(), key: idemKey, attempt: 0 };

    state.messages.push({
        id: crypto.randomUUID(),
        kind: 'user',
        text,
    });
    if (input) {
        input.value = '';
    }
    render();
    scrollMessagesToEnd();

    const client = createApiClient(state.token);
    const body = {
        message: text,
        ...(state.conversationId ? { conversation_id: state.conversationId } : {}),
    };
    const useIdem = Boolean(state.conversationId);

    try {
        const res = await sendAssistantMessage(client, body, useIdem ? idemKey : null);
        if (res.status === 401) {
            state.messages.push({
                id: crypto.randomUUID(),
                kind: 'system',
                text: 'Session expired — sign in again.',
            });
            clearSession();
            state.pendingSend = null;
            state.sending = false;
            render();
            return;
        }
        await handleAssistantResponse(res, text, idemKey);
    } catch (e) {
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'system',
            text: errorMessageFromAxios(e),
        });
        state.pendingSend = null;
    }

    state.sending = false;
    render();
    scrollMessagesToEnd();
    focusMessageInput();
}

function handleInsertSampleDemo() {
    state.globalError = null;
    state.messages = [
        {
            id: crypto.randomUUID(),
            kind: 'user',
            text: 'How many 3cm Calacatta slabs do we have in Houston?',
            isSample: true,
        },
        {
            id: crypto.randomUUID(),
            kind: 'assistant',
            text: 'Based on the inventory, there are 42 slabs in Houston.',
            isSample: true,
        },
        {
            id: crypto.randomUUID(),
            kind: 'user',
            text: 'And in Dallas?',
            isSample: true,
        },
        {
            id: crypto.randomUUID(),
            kind: 'assistant',
            text: 'Based on the inventory, there are 18 slabs in Dallas.',
            isSample: true,
        },
    ];
    render();
    scrollMessagesToEnd();
}

/**
 * @param {string} proposalId
 */
async function handleLoadProposal(proposalId) {
    if (!state.token || !state.conversationId || !isPurchasing()) {
        return;
    }
    const loadGen = ++proposalLoadGeneration;
    state.proposalLoadError = null;
    state.loadedProposal = null;
    state.proposalActionPending = true;
    state.skipHintOnProposalLoad = false;
    render();

    const client = createApiClient(state.token);
    const res = await fetchProposal(client, proposalId, state.conversationId);

    if (loadGen !== proposalLoadGeneration) {
        return;
    }

    state.proposalActionPending = false;
    if (res.status !== 200) {
        state.proposalLoadError = formatApiError(res);
        render();
        return;
    }
    const wrapped = res.data;
    state.loadedProposal =
        wrapped?.data !== undefined && typeof wrapped.data === 'object' ? wrapped.data : wrapped;
    state.manualProposalId = '';
    render();
}

function focusProposalLoader() {
    requestAnimationFrame(() => {
        const input = /** @type {HTMLInputElement|null} */ (document.getElementById('manual-proposal-id'));
        const panel = document.getElementById('proposal-loader-panel');
        input?.focus();
        panel?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
}

function handleLoadAnotherProposal() {
    proposalLoadGeneration += 1;
    state.loadedProposal = null;
    state.proposalLoadError = null;
    state.proposalActionPending = false;
    state.manualProposalId = '';
    state.skipHintOnProposalLoad = true;
    state.showProposalIdList = true;
    render();
    void refreshProposalIdList();
    focusProposalLoader();
}

/**
 * @param {string} proposalId
 */
async function copyTextToClipboard(proposalId) {
    const text = String(proposalId);
    try {
        await navigator.clipboard.writeText(text);
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'system',
            text: 'Proposal id copied to clipboard.',
        });
        render();
    } catch {
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'system',
            text: 'Could not copy automatically. Select the id and copy manually.',
        });
        render();
    }
}

async function refreshProposalIdList() {
    if (!state.token || !state.conversationId || !isPurchasing()) {
        return;
    }
    state.proposalIdListLoading = true;
    state.proposalIdListError = null;
    render();

    const client = createApiClient(state.token);
    const res = await fetchProposalList(client, state.conversationId);
    state.proposalIdListLoading = false;

    if (res.status !== 200) {
        state.proposalIdListError = formatApiError(res);
        state.proposalIdList = null;
        render();
        return;
    }

    const rows = res.data?.data;
    state.proposalIdList = Array.isArray(rows) ? rows : [];
    render();
}

async function handleToggleProposalIdList() {
    const next = !state.showProposalIdList;
    state.showProposalIdList = next;
    if (next && state.proposalIdList === null && !state.proposalIdListLoading) {
        await refreshProposalIdList();
    } else {
        render();
    }
}

async function handleConfirmProposal() {
    const p = state.loadedProposal;
    if (!p || !state.token || !state.conversationId || !isPurchasing()) {
        return;
    }
    const pid = String(p.proposal_id ?? '');
    if (!pid || p.status !== 'pending') {
        return;
    }
    state.proposalActionPending = true;
    render();

    const client = createApiClient(state.token);
    const idem = `confirm-web-${pid}-${crypto.randomUUID()}`;
    const res = await confirmProposal(client, pid, state.conversationId, idem);

    state.proposalActionPending = false;
    if (res.status !== 200 && res.status !== 201) {
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'system',
            text: `Confirm failed: ${formatApiError(res)}`,
        });
        render();
        return;
    }
    const body =
        res.data?.data !== undefined && typeof res.data.data === 'object' ? res.data.data : res.data;
    state.messages.push({
        id: crypto.randomUUID(),
        kind: 'assistant',
        text: `Purchase order confirmed. PO #${body?.purchase_order_id ?? '?'} (${body?.purchase_order_status ?? ''}).`,
        success: true,
        assistantPayload: null,
    });
    await handleLoadProposal(pid);
    render();
    scrollMessagesToEnd();
}

async function handleCancelProposal() {
    const p = state.loadedProposal;
    if (!p || !state.token || !state.conversationId || !isPurchasing()) {
        return;
    }
    const pid = String(p.proposal_id ?? '');
    if (!pid || p.status !== 'pending') {
        return;
    }
    state.proposalActionPending = true;
    render();

    const client = createApiClient(state.token);
    const res = await cancelProposal(client, pid, state.conversationId);

    state.proposalActionPending = false;
    if (res.status !== 200) {
        state.messages.push({
            id: crypto.randomUUID(),
            kind: 'system',
            text: `Cancel failed: ${formatApiError(res)}`,
        });
        render();
        return;
    }
    state.messages.push({
        id: crypto.randomUUID(),
        kind: 'system',
        text: 'Proposal cancelled.',
    });
    await handleLoadProposal(pid);
    render();
}

function attachListeners() {
    const loginForm = document.getElementById('login-form');
    loginForm?.addEventListener('submit', handleLoginSubmit);

    document.getElementById('btn-logout')?.addEventListener('click', handleLogout);
    document.getElementById('btn-new-conv')?.addEventListener('click', handleNewConversation);
    document.getElementById('btn-sample')?.addEventListener('click', handleInsertSampleDemo);
    document.getElementById('chat-form')?.addEventListener('submit', handleSend);

    const ta = /** @type {HTMLTextAreaElement|null} */ (document.getElementById('message-input'));
    ta?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSend();
        }
    });

    document.getElementById('btn-toggle-details')?.addEventListener('click', () => {
        state.showDetails = !state.showDetails;
        render();
    });

    document.getElementById('manual-proposal-id')?.addEventListener('input', (e) => {
        const v = /** @type {HTMLInputElement} */ (e.target).value;
        state.manualProposalId = v;
    });

    document.getElementById('btn-load-proposal')?.addEventListener('click', () => {
        const manual = /** @type {HTMLInputElement|null} */ (document.getElementById('manual-proposal-id'));
        const hinted = state.skipHintOnProposalLoad ? '' : (state.hintedProposalId || '');
        const id = (manual?.value?.trim() || hinted || '').toString();
        if (id) {
            void handleLoadProposal(id);
        }
    });

    document.getElementById('btn-toggle-proposal-list')?.addEventListener('click', () => {
        void handleToggleProposalIdList();
    });

    document.getElementById('btn-load-another-proposal')?.addEventListener('click', () => {
        handleLoadAnotherProposal();
    });

    document.getElementById('proposal-id-list')?.addEventListener('click', (e) => {
        const target = /** @type {HTMLElement|null} */ (e.target instanceof HTMLElement ? e.target.closest('[data-proposal-id]') : null);
        if (!target) {
            return;
        }
        const pid = target.getAttribute('data-proposal-id');
        const action = target.getAttribute('data-proposal-action');
        if (!pid) {
            return;
        }
        if (action === 'copy') {
            void copyTextToClipboard(pid);
            return;
        }
        if (action === 'use') {
            proposalLoadGeneration += 1;
            state.manualProposalId = pid;
            state.loadedProposal = null;
            state.skipHintOnProposalLoad = false;
            render();
            const input = /** @type {HTMLInputElement|null} */ (document.getElementById('manual-proposal-id'));
            input?.focus();
            input?.select();
        }
    });

    document.getElementById('btn-confirm-proposal')?.addEventListener('click', () => {
        void handleConfirmProposal();
    });
    document.getElementById('btn-cancel-proposal')?.addEventListener('click', () => {
        void handleCancelProposal();
    });
}

function render() {
    const loggedIn = Boolean(state.token && state.user);

    root.innerHTML = loggedIn ? layoutChat() : layoutLogin();
    attachListeners();
    requestAnimationFrame(scrollMessagesToEnd);
}

function layoutLogin() {
    const emailVal = typeof state._loginEmail === 'string' ? state._loginEmail : '';
    const err = state.globalError
        ? `<p class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2" role="alert">${escapeHtml(state.globalError)}</p>`
        : '';

    return `
<div class="min-h-screen flex flex-col items-center justify-center p-4 sm:p-8">
  <div class="w-full max-w-md rounded-2xl bg-white shadow-md border border-slate-200/80 p-8">
    <h1 class="text-xl font-semibold text-slate-800 mb-1">Stone ERP</h1>
    <p class="text-sm text-slate-500 mb-6">Sign in with your demo account (Sanctum token).</p>
    <form id="login-form" class="space-y-4" novalidate>
      <div>
        <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email</label>
        <input type="email" id="email" name="email" required autocomplete="username" value="${escapeHtml(emailVal)}"
          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400" />
      </div>
      <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password"
          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400" />
      </div>
      ${err}
      <button type="submit" ${state.sending ? 'disabled' : ''}
        class="w-full rounded-lg bg-[#1e3a5f] text-white text-sm font-medium py-2.5 hover:bg-[#152a45] disabled:opacity-50">
        ${state.sending ? 'Signing in…' : 'Sign in'}
      </button>
    </form>
    <p class="mt-4 text-xs text-slate-400">Token is stored in sessionStorage for this tab only.</p>
  </div>
</div>`;
}

function layoutChat() {
    const role = state.user?.role ?? '';
    const roleLabel = role === 'purchasing' ? 'Purchasing' : 'Sales';
    const proposalPanel = isPurchasing() ? proposalSidebarHtml() : '';

    return `
<div class="h-screen flex flex-col items-center px-2 sm:px-4 min-h-0">
  <div class="w-[min(92vw,1280px)] max-w-[1280px] flex-1 min-h-0 flex flex-col rounded-2xl border border-slate-200/90 bg-white shadow-lg overflow-hidden">
    <header class="shrink-0 flex flex-wrap items-center gap-2 justify-between px-4 py-3 bg-[#1e3a5f] text-white">
      <div class="flex items-center gap-2 min-w-0">
        <h1 class="text-base sm:text-lg font-semibold truncate">Slab Inventory Assistant</h1>
        <span class="text-[10px] sm:text-xs font-medium uppercase tracking-wide bg-white/15 px-2 py-0.5 rounded-full">${escapeHtml(roleLabel)}</span>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-slate-200 truncate max-w-[12rem] sm:max-w-xs" title="${escapeHtml(state.user?.email ?? '')}">${escapeHtml(state.user?.name ?? '')}</span>
        <button type="button" id="btn-new-conv" class="text-xs font-medium bg-white/10 hover:bg-white/20 px-2 py-1 rounded-lg">New chat</button>
        <button type="button" id="btn-sample" class="text-xs font-medium bg-amber-500/90 hover:bg-amber-500 text-slate-900 px-2 py-1 rounded-lg">Load sample script</button>
        <button type="button" id="btn-logout" class="text-xs font-medium bg-red-500/90 hover:bg-red-500 px-2 py-1 rounded-lg">Log out</button>
      </div>
    </header>
    <div class="flex flex-1 min-h-0 flex-col lg:flex-row">
      <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div id="chat-scroll" class="flex-1 overflow-y-auto px-3 sm:px-5 py-4 bg-[#f4f5f7] space-y-3">
          ${state.messages.length === 0 ? emptyStateHtml() : state.messages.map((m) => bubbleHtml(m)).join('')}
          ${state.sending ? loadingBubbleHtml() : ''}
        </div>
        <footer class="shrink-0 border-t border-slate-200 bg-white px-3 sm:px-4 py-3">
          ${state.globalError ? `<p class="text-xs text-red-600 mb-2">${escapeHtml(state.globalError)}</p>` : ''}
          <form id="chat-form" class="flex gap-2 items-end">
            <label class="sr-only" for="message-input">Message</label>
            <textarea id="message-input" rows="2" placeholder="Ask about inventory, POs, or costs (role permitting)…"
              class="flex-1 resize-y min-h-[44px] max-h-40 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
              ${state.sending ? 'disabled' : ''}></textarea>
            <button type="submit" ${state.sending ? 'disabled' : ''}
              class="shrink-0 rounded-xl bg-[#1e3a5f] text-white text-sm font-medium px-4 py-2.5 hover:bg-[#152a45] disabled:opacity-50 h-fit">
              Send
            </button>
          </form>
          <p class="text-[10px] text-slate-400 mt-2">Enter to send · Shift+Enter for a new line · Idempotency is used automatically when continuing a conversation.</p>
        </footer>
      </div>
      ${proposalPanel}
    </div>
  </div>
  ${state.conversationId ? `<p class="shrink-0 py-1 text-[10px] text-slate-400 font-mono truncate max-w-[min(92vw,1280px)]">Conversation: ${escapeHtml(state.conversationId)}</p>` : ''}
</div>`;
}

function emptyStateHtml() {
    return `<div class="flex flex-col items-center justify-center text-center py-12 text-slate-500 text-sm">
      <p class="font-medium text-slate-600 mb-1">No messages yet</p>
      <p>Ask a question or use <strong>Load sample script</strong> for a static demo transcript (not live).</p>
    </div>`;
}

function loadingBubbleHtml() {
    return `<div class="flex justify-start">
      <div class="max-w-[85%] w-fit rounded-2xl rounded-bl-md bg-white border border-slate-200 px-3.5 py-2 text-sm text-slate-500 shadow-sm">
        <span class="inline-block animate-pulse leading-snug">Assistant is thinking…</span>
      </div>
    </div>`;
}

/**
 * @param {ChatMessage} m
 */
function bubbleHtml(m) {
    const sampleTag = m.isSample
        ? `<span class="ml-2 text-[9px] uppercase tracking-wide font-semibold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Sample demo (not API)</span>`
        : '';

    if (m.kind === 'user') {
        return `<div class="flex justify-end">
          <div class="max-w-[85%] w-fit rounded-2xl rounded-br-md bg-[#1e3a5f] text-white px-3.5 py-2 text-sm shadow-sm border border-[#1a3352]/40">
            <div class="flex flex-col gap-0.5">
              <div class="flex items-center gap-1.5 flex-wrap leading-none"><span class="text-[10px] uppercase tracking-wide opacity-75">You</span>${sampleTag}</div>
              <p class="m-0 leading-snug whitespace-pre-wrap">${escapeHtml(m.text)}</p>
            </div>
          </div>
        </div>`;
    }

    if (m.kind === 'system') {
        return `<div class="flex justify-center">
          <div class="max-w-[90%] rounded-lg bg-amber-50 border border-amber-200 text-amber-900 px-3 py-2 text-xs">${escapeHtml(m.text)}</div>
        </div>`;
    }

    const ok = m.success !== false;
    const border = ok ? 'border-slate-200' : 'border-red-200 bg-red-50/60';

    const meta = m.assistantPayload && state.showDetails ? assistantMetaHtml(m.assistantPayload) : '';

    return `<div class="flex justify-start">
      <div class="max-w-[85%] w-fit rounded-2xl rounded-bl-md bg-white border ${border} px-3.5 py-2 text-sm text-slate-800 shadow-sm">
        <div class="flex flex-col gap-0.5">
          <div class="flex items-center gap-1.5 flex-wrap leading-none">
            <span class="text-[10px] uppercase tracking-wide font-medium text-slate-500">Assistant</span>
            ${sampleTag}
            ${ok ? '' : '<span class="text-[10px] font-semibold text-red-700">Incomplete or error</span>'}
          </div>
          <p class="m-0 leading-snug whitespace-pre-wrap">${escapeHtml(m.text)}</p>
          ${meta}
        </div>
      </div>
    </div>`;
}

/**
 * @param {Record<string, unknown>} data
 */
function assistantMetaHtml(data) {
    const prov = data.provider ?? '—';
    const model = data.model ?? '—';
    const u = data.usage && typeof data.usage === 'object' ? data.usage : null;
    const tc = data.tool_calls ?? '—';
    const ti = data.tool_iterations ?? '—';
    const tok = u && 'total_tokens' in u ? u.total_tokens : '—';

    return `<details class="mt-1.5 text-[10px] text-slate-500 border-t border-slate-100 pt-1.5">
      <summary class="cursor-pointer select-none font-medium text-slate-600">Technical details</summary>
      <dl class="mt-1 grid grid-cols-2 gap-x-2 gap-y-0.5">
        <dt>Provider</dt><dd>${escapeHtml(String(prov))}</dd>
        <dt>Model</dt><dd>${escapeHtml(String(model))}</dd>
        <dt>Tool calls</dt><dd>${escapeHtml(String(tc))}</dd>
        <dt>Iterations</dt><dd>${escapeHtml(String(ti))}</dd>
        <dt>Total tokens</dt><dd>${escapeHtml(String(tok))}</dd>
      </dl>
    </details>`;
}

function proposalIdListPanelHtml() {
    const toggleLabel = state.showProposalIdList ? 'Hide proposal ids' : 'Show proposal ids';
    const disabled = !state.conversationId || state.proposalActionPending;

    let listBody = '';
    if (state.showProposalIdList) {
        if (state.proposalIdListLoading) {
            listBody = '<p class="text-[11px] text-slate-500 px-1 py-2">Loading proposals for this conversation…</p>';
        } else if (state.proposalIdListError) {
            listBody = `<p class="text-[11px] text-red-700 px-1 py-2">${escapeHtml(state.proposalIdListError)}</p>`;
        } else if (!state.proposalIdList || state.proposalIdList.length === 0) {
            listBody =
                '<p class="text-[11px] text-slate-500 px-1 py-2">No proposals in this conversation yet.</p>';
        } else {
            listBody = `
              <div id="proposal-id-list" class="mt-2 rounded-lg border border-slate-200 bg-white" role="region" aria-label="Proposal ids for this conversation">
              <ul class="divide-y divide-slate-100 text-[11px] m-0 p-0 list-none">
                ${state.proposalIdList
                    .map((row) => {
                        const pid = String(row.proposal_id ?? '');
                        const status = String(row.status ?? '');
                        const supplier = row.supplier_name ? String(row.supplier_name) : '';
                        return `
                  <li class="px-2 py-2 flex flex-col gap-1.5">
                    <div class="flex items-start justify-between gap-2">
                      <div class="min-w-0 flex-1">
                        <p class="font-mono text-[10px] text-slate-800 break-all" title="${escapeHtml(pid)}">${escapeHtml(pid)}</p>
                        <p class="text-slate-500 mt-0.5">${escapeHtml(status)}${supplier ? ` · ${escapeHtml(supplier)}` : ''}</p>
                      </div>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                      <button type="button" data-proposal-id="${escapeHtml(pid)}" data-proposal-action="copy"
                        class="text-[10px] font-medium rounded-md border border-slate-300 px-2 py-0.5 hover:bg-slate-50">
                        Copy UUID
                      </button>
                      <button type="button" data-proposal-id="${escapeHtml(pid)}" data-proposal-action="use"
                        class="text-[10px] font-medium rounded-md border border-slate-300 px-2 py-0.5 hover:bg-slate-50">
                        Use in loader
                      </button>
                    </div>
                  </li>`;
                    })
                    .join('')}
              </ul>
              </div>`;
        }
    }

    return `
      <div class="rounded-lg border border-slate-200 bg-white/80 px-2 py-2">
        <button type="button" id="btn-toggle-proposal-list" ${disabled ? 'disabled' : ''}
          class="w-full text-center text-[11px] font-semibold rounded-lg bg-[#1e3a5f] text-white px-3 py-2 hover:bg-[#152a46] disabled:opacity-40 disabled:cursor-not-allowed">
          ${escapeHtml(toggleLabel)} for this conversation
        </button>
        ${listBody}
      </div>`;
}

function proposalLoadControlsHtml() {
    return `
        <div id="proposal-loader-panel" class="rounded-lg border border-slate-200 bg-white px-2 py-2 space-y-2">
          <div class="flex gap-2 items-end">
            <div class="flex-1 min-w-0">
              <label for="manual-proposal-id" class="text-[10px] font-medium text-slate-600">Proposal id (UUID)</label>
              <input id="manual-proposal-id" type="text" spellcheck="false" autocomplete="off"
                placeholder="e.g.(UUID): 6b78a919-f3a4-4c2a-9b1c-8e7d6f5a4b3c"
                class="w-full mt-0.5 rounded-lg border border-slate-300 px-2 py-1.5 text-[11px] font-mono"
                value="${escapeHtml(state.manualProposalId ?? '')}" />
            </div>
            <button type="button" id="btn-load-proposal" ${!state.conversationId || state.proposalActionPending ? 'disabled' : ''}
              class="shrink-0 text-xs font-medium rounded-lg bg-[#1e3a5f] text-white px-3 py-1.5 disabled:opacity-40">
              Load
            </button>
          </div>
        </div>`;
}

function proposalSidebarHtml() {
    const hint = state.hintedProposalId
        ? `<p class="text-xs text-emerald-800 bg-emerald-50 border border-emerald-200 rounded-lg px-2 py-1">Detected proposal id from the last reply. Use <strong class="font-mono text-[10px]">${escapeHtml(state.hintedProposalId)}</strong> or pick from the list below.</p>`
        : '<p class="text-xs text-slate-500">Paste a proposal UUID from the assistant or pick one from the list, then click Load.</p>';

    const loadErr = state.proposalLoadError
        ? `<p class="text-xs text-red-700">${escapeHtml(state.proposalLoadError)}</p>`
        : '';

    const p = state.loadedProposal;
    let body = '<p class="text-xs text-slate-500">Load a pending proposal to preview lines and confirm.</p>';

    if (p && typeof p === 'object') {
        const lines = Array.isArray(p.lines) ? p.lines : [];
        const showCosts = isPurchasing();
        body = `
          <div class="rounded-lg border border-slate-200 bg-white p-2 space-y-2 text-xs shadow-sm">
            <p class="text-[10px] font-mono text-slate-600 break-all" title="Loaded proposal id">${escapeHtml(String(p.proposal_id ?? ''))}</p>
            <p><span class="text-slate-500">Status:</span> <strong>${escapeHtml(String(p.status ?? ''))}</strong></p>
            <p><span class="text-slate-500">Supplier:</span> ${escapeHtml(String(p.supplier_name ?? ''))} (id ${escapeHtml(String(p.supplier_id ?? ''))})</p>
            <p><span class="text-slate-500">Order / ETA:</span> ${escapeHtml(String(p.order_date ?? ''))} → ${escapeHtml(String(p.eta ?? ''))}</p>
            ${showCosts ? `<p><span class="text-slate-500">Freight:</span> ${escapeHtml(String(p.freight_cost ?? ''))} · <span class="text-slate-500">Subtotal:</span> ${escapeHtml(String(p.subtotal ?? ''))}</p>` : ''}
            <div class="overflow-x-auto max-h-40 border border-slate-200 rounded-lg">
              <table class="w-full text-[11px]">
                <thead class="bg-slate-100 text-slate-600"><tr>
                  <th class="text-left px-2 py-1">SKU</th><th class="text-left px-2 py-1">Dest.</th><th class="text-left px-2 py-1">Qty</th>
                  ${showCosts ? '<th class="text-right px-2 py-1">Unit</th>' : ''}
                </tr></thead>
                <tbody>
                  ${lines
                      .map(
                          (line) => `
                    <tr class="border-t border-slate-100">
                      <td class="px-2 py-1">${escapeHtml(String(line.product_sku ?? ''))}</td>
                      <td class="px-2 py-1">${escapeHtml(String(line.warehouse_name ?? line.warehouse_id ?? ''))}</td>
                      <td class="px-2 py-1">${escapeHtml(String(line.quantity ?? ''))}</td>
                      ${showCosts ? `<td class="px-2 py-1 text-right">${escapeHtml(String(line.unit_cost ?? ''))}</td>` : ''}
                    </tr>`,
                      )
                      .join('')}
                </tbody>
              </table>
            </div>
            <div class="flex flex-wrap gap-2 pt-1">
              <button type="button" id="btn-confirm-proposal" ${p.status !== 'pending' || state.proposalActionPending ? 'disabled' : ''}
                class="text-xs font-medium rounded-lg bg-emerald-600 text-white px-3 py-1.5 hover:bg-emerald-700 disabled:opacity-40">
                Confirm draft PO
              </button>
              <button type="button" id="btn-cancel-proposal" ${p.status !== 'pending' || state.proposalActionPending ? 'disabled' : ''}
                class="text-xs font-medium rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50 disabled:opacity-40">
                Cancel proposal
              </button>
            </div>
            <button type="button" id="btn-load-another-proposal" class="text-[10px] text-slate-500 underline hover:text-slate-700">
              Go to proposal list...
            </button>
          </div>`;
    }

    const loadSection = p ? '' : `${proposalLoadControlsHtml()}${proposalIdListPanelHtml()}`;
    const browseAfterDetails = p ? proposalIdListPanelHtml() : '';

    return `
      <aside class="w-full lg:w-[min(28%,320px)] shrink-0 border-t lg:border-t-0 lg:border-l border-slate-200 bg-slate-50 p-3 flex flex-col min-h-0 overflow-hidden">
        <div class="flex-1 min-h-0 overflow-y-auto flex flex-col gap-3">
          <div class="flex items-center justify-between gap-2 shrink-0">
            <h2 class="text-xs font-semibold text-slate-700 uppercase tracking-wide">Draft PO (Purchasing)</h2>
            <button type="button" id="btn-toggle-details" class="text-[10px] text-slate-500 underline">${state.showDetails ? 'Hide' : 'Show'} reply details</button>
          </div>
          ${hint}
          ${loadSection}
          ${loadErr}
          ${body}
          ${browseAfterDetails}
        </div>
        <p class="text-[10px] text-slate-400 shrink-0 pt-2 mt-2 border-t border-slate-200/80 leading-snug">Uses <code class="bg-slate-200/80 px-1 rounded">conversation_key</code> = current conversation UUID. Confirmation never runs automatically.</p>
      </aside>`;
}

/**
 * @param {string} s
 */
function escapeHtml(s) {
    return s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

loadSession();
reconcilePendingRunStorage();
render();
if (state.token && state.user) {
    focusMessageInput();
}
void tryResumePendingAsyncRun();
