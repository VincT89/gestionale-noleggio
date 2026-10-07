import test from 'node:test';
import assert from 'node:assert/strict';
import { noticeIsCurrent, readNotice, noticeStorageKey } from '../../resources/js/public-cookie-notice.js';

const now = Date.UTC(2026, 9, 6);
const version = '2026-10-06';
const day = 86400000;
const record = (dismissedAt, recordVersion = version) => JSON.stringify({ version: recordVersion, dismissedAt });

test('a dismissed notice is remembered without renewing the date on each visit', () => {
    const value = record(now - 179 * day);
    const storage = { getItem: () => value, setItem: () => assert.fail('Must not renew acknowledgement') };
    assert.equal(readNotice(storage, version, 180, now), true);
});

test('expired or superseded acknowledgement is removed', () => {
    for (const value of [record(now - 180 * day), record(now - day, 'old')]) {
        const removed = [];
        assert.equal(readNotice({ getItem: () => value, removeItem: key => removed.push(key) }, version, 180, now), false);
        assert.deepEqual(removed, [noticeStorageKey]);
    }
});

test('missing, corrupted, future or incomplete records do not suppress the notice', () => {
    for (const value of [null, '', 'broken', 'null', '{}', 'true', record(now + 1), JSON.stringify({ version, dismissedAt: 'today' })]) {
        assert.equal(noticeIsCurrent(value, version, 180, now), false);
    }
});

test('browser storage restrictions cannot break navigation', () => {
    const blocked = { getItem: () => { throw new Error('SecurityError'); } };
    assert.equal(readNotice(blocked, version, 180, now), false);
    assert.equal(readNotice(null, version, 180, now), false);
    assert.equal(readNotice({ getItem: () => 'broken', removeItem: () => { throw new Error('SecurityError'); } }, version, 180, now), false);
});
