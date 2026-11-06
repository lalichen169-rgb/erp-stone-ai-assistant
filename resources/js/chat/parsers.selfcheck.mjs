import assert from 'assert';
import { extractProposalIdFromAssistantText } from './parsers.js';

assert.strictEqual(
    extractProposalIdFromAssistantText('Pending proposal id: 42 — confirm in the app.'),
    '42',
);
assert.strictEqual(extractProposalIdFromAssistantText('No proposal here.'), null);
console.log('parsers.selfcheck: ok');
