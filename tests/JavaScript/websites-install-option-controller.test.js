'use strict';

// The Websites page opens on the installation method and consent banner choice
// last picked in this browser, and falls back to its default when nothing
// valid is saved.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
    path.join(__dirname, '../../assets/controllers/pages/websites/install_option_controller.js'),
    'utf8'
);
const KEY = 'aggregate.websites.install-option';

function page({href, shown, saved, blocked = false, turbo = true, preview = false}) {
    const values = new Map(saved === undefined ? [] : [[KEY, saved]]);
    const storage = {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key)
    };
    const visits = [];
    const replaced = [];
    const view = {location: {href, replace: (url) => replaced.push(url)}};
    Object.defineProperty(view, 'localStorage', {get() {
        if (blocked) throw new Error('Storage is unavailable');
        return storage;
    }});
    if (turbo) view.Turbo = {visit: (url, options) => visits.push({url, options})};
    const document = {
        defaultView: view,
        documentElement: {hasAttribute: (name) => preview && name === 'data-turbo-preview'}
    };
    const InstallOption = vm.runInNewContext(
        source.replace(/^import .*;\n/m, '').replace('export default class', 'class InstallOption')
            + '\nInstallOption;',
        {Controller: class {}, URL}
    );
    const controller = Object.assign(new InstallOption(), {
        element: {ownerDocument: document},
        formatValue: shown.format,
        tagsValue: shown.tags,
        cmpValue: shown.cmp
    });
    controller.connect();
    return {values, visits, replaced};
}

const DEFAULT = {format: 'window', tags: false, cmp: true};
const TAGS_AND_CMP = {format: 'window', tags: true, cmp: true};

test('a choice in the address is remembered and never redirected', () => {
    const {values, visits} = page({
        href: 'https://a.test/dashboard?format=window&tags=1&cmp=1',
        shown: TAGS_AND_CMP,
        saved: JSON.stringify({format: 'query', tags: false, cmp: false})
    });
    assert.deepEqual(JSON.parse(values.get(KEY)), TAGS_AND_CMP);
    assert.equal(visits.length, 0);
});

test('an address without a choice opens on the remembered one', () => {
    const {visits} = page({
        href: 'https://a.test/dashboard',
        shown: DEFAULT,
        saved: JSON.stringify({format: 'window', tags: true, cmp: false})
    });
    assert.equal(visits.length, 1);
    assert.equal(visits[0].options.action, 'replace');
    const url = new URL(visits[0].url);
    assert.equal(url.pathname, '/dashboard');
    assert.equal(url.searchParams.get('format'), 'window');
    assert.equal(url.searchParams.get('tags'), '1');
    assert.equal(url.searchParams.get('cmp'), '0');
});

test('the query format is restored without the tag manager', () => {
    const {visits} = page({
        href: 'https://a.test/dashboard',
        shown: DEFAULT,
        saved: JSON.stringify({format: 'query', tags: false, cmp: true})
    });
    const url = new URL(visits[0].url);
    assert.equal(url.searchParams.get('format'), 'query');
    assert.equal(url.searchParams.has('tags'), false);
    assert.equal(url.searchParams.get('cmp'), '1');
});

test('no redirect when the remembered choice is already shown', () => {
    const {visits, replaced} = page({
        href: 'https://a.test/dashboard',
        shown: DEFAULT,
        saved: JSON.stringify(DEFAULT)
    });
    assert.equal(visits.length + replaced.length, 0);
});

test('missing or invalid saved values keep the default and are dropped', () => {
    const invalid = [
        'not json', 'null', '"window"', '[]', '{}',
        JSON.stringify({format: 'script', tags: false, cmp: true}),
        JSON.stringify({format: 'window', tags: '1', cmp: true}),
        JSON.stringify({format: 'window', tags: false, cmp: 'yes'})
    ];
    for (const saved of [undefined, ...invalid]) {
        const {values, visits} = page({href: 'https://a.test/dashboard', shown: DEFAULT, saved});
        assert.equal(visits.length, 0, `no redirect for ${saved}`);
        assert.equal(values.has(KEY), false, `dropped ${saved}`);
    }
});

test('blocked storage leaves the page on its default', () => {
    const {visits} = page({href: 'https://a.test/dashboard', shown: DEFAULT, blocked: true});
    assert.equal(visits.length, 0);
    assert.doesNotThrow(() => page({
        href: 'https://a.test/dashboard?format=query', shown: DEFAULT, blocked: true
    }));
});

test('without Turbo the address is replaced, and a Turbo preview is left alone', () => {
    const saved = JSON.stringify(TAGS_AND_CMP);
    const plain = page({href: 'https://a.test/dashboard', shown: DEFAULT, saved, turbo: false});
    assert.equal(plain.replaced.length, 1);
    const preview = page({href: 'https://a.test/dashboard', shown: DEFAULT, saved, preview: true});
    assert.equal(preview.visits.length, 0);
});
