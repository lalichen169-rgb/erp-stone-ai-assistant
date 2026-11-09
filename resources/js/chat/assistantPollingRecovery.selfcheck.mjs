import assert from 'assert';
import {
    AssistantRunPollError,
    classifyAssistantRunPollHttpStatus,
    pollAssistantRunUntilDone,
    shouldResumePendingRun,
    truncatePendingRunMessage,
} from './assistantPolling.js';

assert.strictEqual(shouldResumePendingRun('7', 7, 'run-abc'), true);
assert.strictEqual(shouldResumePendingRun('7', 8, 'run-abc'), false);
assert.strictEqual(shouldResumePendingRun(null, 7, 'run-abc'), false);
assert.strictEqual(shouldResumePendingRun('7', 7, ''), false);
assert.strictEqual(shouldResumePendingRun('7', 99, 'run-abc'), false);

assert.strictEqual(truncatePendingRunMessage('  hello '), 'hello');
assert.strictEqual(truncatePendingRunMessage('x'.repeat(5000), 4000).length, 4000);

assert.strictEqual(classifyAssistantRunPollHttpStatus(200), 'ok');
assert.strictEqual(classifyAssistantRunPollHttpStatus(401), 'unauthenticated');
assert.strictEqual(classifyAssistantRunPollHttpStatus(401), 'unauthenticated');
assert.strictEqual(classifyAssistantRunPollHttpStatus(404), 'not_found');
assert.strictEqual(classifyAssistantRunPollHttpStatus(503), 'retry');

let pollCalls = 0;
const completed = await pollAssistantRunUntilDone(
    async () => {
        pollCalls += 1;
        return {
            status: 200,
            data: { data: { status: pollCalls < 2 ? 'processing' : 'completed', answer: 'Done.' } },
        };
    },
    'run-1',
    { maxDurationMs: 5000 },
);
assert.strictEqual(completed.terminalData.status, 'completed');
assert.ok(pollCalls >= 2);

let failAuth = false;
try {
    await pollAssistantRunUntilDone(
        async () => {
            return { status: 401, data: {} };
        },
        'run-1',
        { maxDurationMs: 1000 },
    );
} catch (e) {
    failAuth = e instanceof AssistantRunPollError && e.code === 'unauthenticated';
}
assert.strictEqual(failAuth, true);

let failMissing = false;
try {
    await pollAssistantRunUntilDone(
        async () => {
            return { status: 404, data: {} };
        },
        'run-missing',
        { maxDurationMs: 1000 },
    );
} catch (e) {
    failMissing = e instanceof AssistantRunPollError && e.code === 'not_found';
}
assert.strictEqual(failMissing, true);

let failRun = false;
try {
    await pollAssistantRunUntilDone(
        async () => {
            return { status: 200, data: { data: { status: 'failed', error: { message: 'Queue dispatch failed.' } } } };
        },
        'run-fail',
        { maxDurationMs: 1000 },
    );
} catch {
    failRun = true;
}
assert.strictEqual(failRun, false);
const failedPoll = await pollAssistantRunUntilDone(
    async () => ({
        status: 200,
        data: { data: { status: 'failed', error: { message: 'Queue dispatch failed.' } } },
    }),
    'run-fail',
    { maxDurationMs: 1000 },
);
assert.strictEqual(failedPoll.terminalData.status, 'failed');

console.log('assistantPollingRecovery.selfcheck: ok');
