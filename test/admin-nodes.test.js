'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const plugin = process.env.XMRPAY_PLUGIN_DIR;
if (!plugin) throw new Error('set XMRPAY_PLUGIN_DIR');
const nodes = require(path.resolve(plugin, 'assets/admin-nodes.js'));

test('legacy node lists become unauthenticated rows', () => {
    const rows = nodes.parseNodes('https://one.example:18081\nhttps://two.example:18081');
    assert.deepEqual(rows.map(row => [row.url, row.auth]), [
        ['https://one.example:18081', 'none'],
        ['https://two.example:18081', 'none'],
    ]);
});

test('structured rows preserve credentials per node', () => {
    const input = [
        { url: 'https://one.example:18081', auth: 'basic', username: 'one', password: ' first ', allow_insecure_http: false },
        { url: 'http://umbrel.local:18081', auth: 'digest', username: 'two', password: 'second', allow_insecure_http: true },
    ];
    assert.deepEqual(nodes.parseNodes(nodes.serializeNodes(input)), input);
});

test('unauthenticated rows cannot retain credentials', () => {
    const encoded = nodes.serializeNodes([{ url: 'https://node.example', auth: 'none', username: 'old', password: 'old' }]);
    assert.deepEqual(JSON.parse(encoded), [{ url: 'https://node.example', auth: 'none', username: '', password: '', allow_insecure_http: false }]);
});

test('empty stored values still render one editable row', () => {
    assert.deepEqual(nodes.parseNodes(''), [{ url: '', auth: 'none', username: '', password: '', allow_insecure_http: false }]);
});
