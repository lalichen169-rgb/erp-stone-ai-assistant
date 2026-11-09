import assert from 'assert';
import { isAsyncAssistantAccepted } from './assistantPolling.js';

assert.strictEqual(
    isAsyncAssistantAccepted(202, { success: true, data: { async: true, run_id: 'run-1' } }),
    true,
);
assert.strictEqual(isAsyncAssistantAccepted(200, { data: { async: true, run_id: 'x' } }), false);
assert.strictEqual(isAsyncAssistantAccepted(202, { data: { async: false } }), false);
console.log('assistantPolling.selfcheck: ok');
