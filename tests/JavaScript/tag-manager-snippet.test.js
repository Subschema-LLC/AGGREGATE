'use strict';

// "Get developer snippet" on a tag row: posts the row as edited, shows the
// server's snippet, reason and attention items, and shares or stops sharing
// the saved tag's read-only link.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/components/tag_manager/snippet_controller.js'), 'utf8');

function element(extra = {}) {
  const attributes = {};
  const listeners = {};
  return Object.assign({
    hidden: true, textContent: '', value: '', disabled: false, focused: 0, selected: 0, children: [], dataset: {},
    setAttribute(name, value) { attributes[name] = String(value); },
    getAttribute(name) { return attributes[name] ?? null; },
    focus() { this.focused++; },
    select() { this.selected++; },
    replaceChildren(...children) { this.children = children; },
    addEventListener(type, listener) { (listeners[type] ||= []).push(listener); },
    removeEventListener(type, listener) { listeners[type] = (listeners[type] || []).filter((l) => l !== listener); },
    dispatch(type) { (listeners[type] || []).forEach((listener) => listener({type})); },
  }, extra);
}

function field(name, value, extra = {}) {
  return Object.assign({name, value, type: 'text', disabled: false, checked: false}, extra);
}

function setup({answers = {}, clipboardFails = false, rowFields, variables} = {}) {
  const requests = [];
  const documentListeners = {};
  const rowFieldsList = rowFields ?? [
    field('tags[3][id]', 'signup'),
    field('tags[3][event_json]', '{"eventName":"signup"}'),
    field('tags[3][enabled]', '1', {type: 'checkbox', checked: false}),
    field('tags[3][consent]', 'analytics', {disabled: true}),
  ];
  const variableFields = variables ?? [field('variables[0][alias]', 'plan'), field('variables[0][path]', 'signup.plan')];
  const row = element({querySelectorAll: () => rowFieldsList});
  const form = element({
    querySelector: (selector) => ({
      '[name="_csrf_token"]': field('_csrf_token', 'token-1'),
      '[name="site"]': field('site', 'abc'),
    })[selector] ?? null,
    querySelectorAll: (selector) => selector === '[name^="variables["]' ? variableFields : [],
  });
  const attentionBox = element();
  const targets = {
    button: element({textContent: 'Get developer snippet'}), panel: element(), heading: element(), reason: element(),
    code: element(), attention: element({closest: () => attentionBox}), stale: element(), live: element(), copy: element({textContent: 'Copy snippet'}),
    shareButton: element(), sharing: element(), shareStatus: element(), shareLinkBox: element(), shareLink: element(),
    copyLink: element(), stopButton: element(),
  };
  const clipboard = [];
  const context = {
    Controller: class {},
    FormData,
    Date,
    setTimeout: () => 0,
    clearTimeout: () => {},
    document: {
      createElement: () => element(),
      addEventListener: (type, listener) => { (documentListeners[type] ||= []).push(listener); },
      removeEventListener: () => {},
      createRange: () => ({selectNodeContents() {}}),
      execCommand: () => false,
    },
    window: {getSelection: () => ({removeAllRanges() {}, addRange() {}})},
    navigator: {clipboard: {writeText: async (text) => {
      if (clipboardFails) throw new Error('blocked');
      clipboard.push(text);
    }}},
    fetch: async (url, options) => {
      const body = Object.fromEntries(options.body.entries());
      requests.push({url, body, method: options.method});
      const answer = answers[url];
      if (answer instanceof Error) throw answer;
      return {json: async () => (typeof answer === 'function' ? answer(body) : answer) ?? {}};
    },
  };
  const Snippet = vm.runInNewContext(source.replace(/^import .*;\n/m, '').replace('export default class', 'class Snippet') + '\nSnippet;', context);
  const controller = Object.assign(new Snippet(), {
    element: {closest: (selector) => (selector === '[data-tag-row]' ? row : form)},
    urlValue: '/dashboard/tag-manager/snippet',
    shareUrlValue: '/dashboard/tag-manager/snippet/share',
    stopUrlValue: '/dashboard/tag-manager/snippet/share/stop',
  });
  for (const [name, target] of Object.entries(targets)) controller[`${name}Target`] = target;
  controller.connect();
  return {controller, targets, requests, row, attentionBox, clipboard, documentListeners};
}

const built = {
  snippet: "window.dataLayer.push({event: 'signup'});\n",
  reason: null,
  attention: ['Plan reads event.detail.plan.'],
  tag: 'signup',
  shared_until: null,
};

test('the row is posted as edited, with the variables, token, site and row index', async () => {
  const {controller, requests, targets} = setup({answers: {'/dashboard/tag-manager/snippet': built}});
  await controller.toggle();

  assert.equal(requests.length, 1);
  assert.equal(requests[0].method, 'POST');
  assert.deepEqual(requests[0].body, {
    'tags[3][id]': 'signup',
    'tags[3][event_json]': '{"eventName":"signup"}',
    'variables[0][alias]': 'plan',
    'variables[0][path]': 'signup.plan',
    _csrf_token: 'token-1',
    site: 'abc',
    snippet_tag: '3',
  }, 'unchecked and disabled fields are left out');
  assert.equal(targets.button.disabled, false);
  assert.equal(targets.button.textContent, 'Get developer snippet', 'the label comes back after "Preparing…"');
});

test('a snippet opens the panel with its attention items and an enabled Share link', async () => {
  const {controller, targets, attentionBox} = setup({answers: {'/dashboard/tag-manager/snippet': built}});
  await controller.toggle();

  assert.equal(targets.panel.hidden, false);
  assert.equal(targets.button.getAttribute('aria-expanded'), 'true');
  assert.equal(targets.heading.focused, 1);
  assert.equal(targets.code.textContent, built.snippet);
  assert.equal(targets.code.hidden, false);
  assert.equal(targets.copy.hidden, false);
  assert.equal(targets.reason.hidden, true);
  assert.deepEqual(targets.attention.children.map((item) => item.textContent), built.attention);
  assert.equal(attentionBox.hidden, false);
  assert.equal(targets.shareButton.hidden, false);
  assert.equal(targets.sharing.hidden, true, 'not shared yet');

  controller.toggle();
  assert.equal(targets.panel.hidden, true);
  assert.equal(targets.button.getAttribute('aria-expanded'), 'false');
  assert.equal(targets.button.focused, 1, 'focus goes back to the button');
});

test('a reason replaces the snippet, and an unsaved tag cannot be shared', async () => {
  for (const answer of [
    {snippet: null, reason: 'Add an event name to the trigger first.', attention: [], tag: 'signup'},
    {error: 'Invalid security token. Reload the page and try again.'},
  ]) {
    const {controller, targets, attentionBox} = setup({answers: {'/dashboard/tag-manager/snippet': answer}});
    await controller.toggle();
    assert.equal(targets.reason.textContent, answer.reason || answer.error);
    assert.equal(targets.reason.hidden, false);
    assert.equal(targets.code.hidden, true);
    assert.equal(targets.copy.hidden, true);
    assert.equal(attentionBox.hidden, true);
    assert.equal(targets.shareButton.hidden, true);
    assert.equal(targets.panel.hidden, false, 'the panel opens with the reason');
  }

  const unsaved = setup({answers: {'/dashboard/tag-manager/snippet': {...built, tag: ''}}});
  await unsaved.controller.toggle();
  assert.equal(unsaved.targets.code.hidden, false);
  assert.equal(unsaved.targets.shareButton.hidden, true);
});

test('a failed request shows the fallback reason instead of a stale snippet', async () => {
  const {controller, targets} = setup({answers: {'/dashboard/tag-manager/snippet': new TypeError('offline')}});
  await controller.toggle();
  assert.match(targets.reason.textContent, /couldn't build the snippet/);
  assert.equal(targets.code.textContent, '');
  assert.equal(targets.button.disabled, false);
});

test('editing the row or the variables marks an open panel stale', async () => {
  const {controller, targets, row, documentListeners} = setup({answers: {'/dashboard/tag-manager/snippet': built}});
  row.dispatch('input');
  assert.equal(targets.stale.hidden, true, 'a closed panel is not marked');

  await controller.toggle();
  assert.equal(targets.stale.hidden, true);
  row.dispatch('change');
  assert.equal(targets.stale.hidden, false);

  await controller.build();
  assert.equal(targets.stale.hidden, true, 'refreshing clears the note');
  documentListeners['tag-manager:variables-changed'].forEach((listener) => listener());
  assert.equal(targets.stale.hidden, false);
});

test('an already shared tag shows when its link stops and offers Stop sharing', async () => {
  const until = '2026-12-02T12:00:00+00:00';
  const {controller, targets} = setup({answers: {'/dashboard/tag-manager/snippet': {...built, shared_until: until}}});
  await controller.toggle();
  assert.equal(targets.sharing.hidden, false);
  assert.equal(targets.shareStatus.textContent, `Shared until ${new Date(until).toLocaleString()}.`);
  assert.equal(targets.stopButton.hidden, false);
  assert.equal(targets.shareLinkBox.hidden, true, 'an old link is never shown again');
});

test('Share link posts the saved tag and shows the link once, selected', async () => {
  const url = 'https://analytics.example/shared-snippet/abc/' + 'a'.repeat(48);
  const {controller, targets, requests} = setup({answers: {
    '/dashboard/tag-manager/snippet': built,
    '/dashboard/tag-manager/snippet/share': {url, shared_until: '2026-12-02T12:00:00+00:00', message: 'Anyone with this link can read this snippet.'},
  }});
  await controller.toggle();
  await controller.share();

  assert.deepEqual(requests[1], {
    url: '/dashboard/tag-manager/snippet/share',
    method: 'POST',
    body: {_csrf_token: 'token-1', site: 'abc', tag: 'signup'},
  });
  assert.equal(targets.shareLink.value, url);
  assert.equal(targets.shareLinkBox.hidden, false);
  assert.equal(targets.shareLink.focused, 1);
  assert.equal(targets.shareLink.selected, 1);
  assert.equal(targets.shareStatus.textContent, 'Anyone with this link can read this snippet.');
  assert.equal(targets.sharing.hidden, false);
  assert.equal(targets.stopButton.hidden, false);
  assert.equal(targets.shareButton.disabled, false);
});

test('a refused share shows the reason and no link', async () => {
  const {controller, targets} = setup({answers: {
    '/dashboard/tag-manager/snippet': built,
    '/dashboard/tag-manager/snippet/share': {error: 'Save this tag first. A shared link shows the saved tag only.'},
  }});
  await controller.toggle();
  await controller.share();
  assert.equal(targets.shareStatus.textContent, 'Save this tag first. A shared link shows the saved tag only.');
  assert.equal(targets.shareLinkBox.hidden, true);
  assert.equal(targets.stopButton.hidden, true);
  assert.equal(targets.shareButton.disabled, false);

  const offline = setup({answers: {'/dashboard/tag-manager/snippet': built, '/dashboard/tag-manager/snippet/share': new TypeError('offline')}});
  await offline.controller.toggle();
  await offline.controller.share();
  assert.match(offline.targets.shareStatus.textContent, /Reload the page and try again/);
});

test('Stop sharing clears the link and says so', async () => {
  const {controller, targets, requests} = setup({answers: {
    '/dashboard/tag-manager/snippet': built,
    '/dashboard/tag-manager/snippet/share': {url: 'https://analytics.example/shared-snippet/abc/x', shared_until: '2026-12-02T12:00:00+00:00', message: 'Shared.'},
    '/dashboard/tag-manager/snippet/share/stop': {shared_until: null, message: 'Sharing stopped. The link no longer opens.'},
  }});
  await controller.toggle();
  await controller.share();
  await controller.stopSharing();

  assert.equal(requests[2].url, '/dashboard/tag-manager/snippet/share/stop');
  assert.equal(requests[2].body.tag, 'signup');
  assert.equal(targets.shareLink.value, '');
  assert.equal(targets.shareLinkBox.hidden, true);
  assert.equal(targets.shareStatus.textContent, 'Sharing stopped. The link no longer opens.');
  assert.equal(targets.stopButton.hidden, true);
  assert.equal(targets.shareButton.focused, 1);
});

test('copying the snippet and the link announces the result', async () => {
  const {controller, targets, clipboard} = setup({answers: {'/dashboard/tag-manager/snippet': built}});
  await controller.toggle();
  await controller.copy();
  assert.deepEqual(clipboard, [built.snippet]);
  assert.equal(targets.live.textContent, 'Snippet copied');
  assert.equal(targets.copy.textContent, 'Copied');

  targets.shareLink.value = 'https://analytics.example/shared-snippet/abc/x';
  await controller.copyLink();
  assert.equal(clipboard[1], targets.shareLink.value);
  assert.equal(targets.live.textContent, 'Link copied');

  const blocked = setup({answers: {'/dashboard/tag-manager/snippet': built}, clipboardFails: true});
  await blocked.controller.toggle();
  await blocked.controller.copy();
  assert.equal(blocked.targets.live.textContent, 'Copy didn\'t work. Press Ctrl+C to copy the selected text.');
  await blocked.controller.copyLink();
  assert.equal(blocked.targets.shareLink.selected, 1);
  assert.match(blocked.targets.live.textContent, /Press Ctrl\+C to copy the selected link/);
});
