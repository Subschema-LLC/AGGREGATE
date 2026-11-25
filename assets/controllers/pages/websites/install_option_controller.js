import { Controller } from '@hotwired/stimulus';

// The Websites page opens on the installation method and consent banner choice
// last picked in this browser. A choice in the address always wins and becomes
// the remembered one; an address without one (the Websites menu link, sign-in,
// a bookmark) is replaced with the remembered choice. A saved value that is
// missing, unreadable or unknown is dropped and the page keeps its default.
const STORAGE_KEY = 'aggregate.websites.install-option';
const CHOICE_PARAMS = ['format', 'tags', 'cmp', 'consent_option'];
const FORMATS = ['window', 'query'];

export default class extends Controller {
    static values = {format: String, tags: Boolean, cmp: Boolean};

    connect() {
        const document = this.element.ownerDocument;
        const view = document.defaultView;
        if (!view || document.documentElement?.hasAttribute('data-turbo-preview')) return;

        const url = new URL(view.location.href);
        const shown = normalize({format: this.formatValue, tags: this.tagsValue, cmp: this.cmpValue});
        if (CHOICE_PARAMS.some((name) => url.searchParams.has(name))) {
            if (shown !== null) write(view, shown);
            return;
        }

        const saved = read(view);
        if (saved === null || same(saved, shown)) return;
        url.searchParams.set('format', saved.format);
        if (saved.tags) url.searchParams.set('tags', '1');
        url.searchParams.set('cmp', saved.cmp ? '1' : '0');
        visit(view, url.toString());
    }
}

function normalize(choice) {
    if (choice === null || typeof choice !== 'object') return null;
    const {format, tags, cmp} = choice;
    if (!FORMATS.includes(format) || typeof tags !== 'boolean' || typeof cmp !== 'boolean') {
        return null;
    }
    // The tag manager snippet always uses window configuration.
    return {format: tags ? 'window' : format, tags, cmp};
}

function same(a, b) {
    return b !== null && a.format === b.format && a.tags === b.tags && a.cmp === b.cmp;
}

function read(view) {
    try {
        const raw = view.localStorage.getItem(STORAGE_KEY);
        if (raw === null) return null;
        const choice = normalize(JSON.parse(raw));
        if (choice === null) view.localStorage.removeItem(STORAGE_KEY);
        return choice;
    } catch (_) {
        try { view.localStorage.removeItem(STORAGE_KEY); } catch (__) {}
        return null;
    }
}

function write(view, choice) {
    try {
        view.localStorage.setItem(STORAGE_KEY, JSON.stringify(choice));
    } catch (_) {}
}

function visit(view, url) {
    if (view.Turbo && typeof view.Turbo.visit === 'function') {
        view.Turbo.visit(url, {action: 'replace'});
    } else {
        view.location.replace(url);
    }
}
