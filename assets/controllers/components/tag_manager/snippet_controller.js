// "Get developer snippet" on a tag row: posts the row as edited to the server, which
// builds the dataLayer.push, and shows the snippet, the reason there is none and the
// items needing attention. It parses no paths itself, so the dashboard and the command
// line print the same text. Nothing is saved or sent.
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'panel', 'heading', 'reason', 'code', 'attention', 'stale', 'live', 'copy'];
    static values = { url: String };

    connect() {
        this.row = this.element.closest('[data-tag-row]');
        this.markStale = () => { if (!this.panelTarget.hidden) this.staleTarget.hidden = false; };
        this.row?.addEventListener('input', this.markStale);
        this.row?.addEventListener('change', this.markStale);
        document.addEventListener('tag-manager:variables-changed', this.markStale);
    }

    disconnect() {
        this.row?.removeEventListener('input', this.markStale);
        this.row?.removeEventListener('change', this.markStale);
        document.removeEventListener('tag-manager:variables-changed', this.markStale);
    }

    toggle() {
        if (!this.panelTarget.hidden) return this.close();
        return this.build();
    }

    close() {
        this.panelTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.buttonTarget.focus();
    }

    escape(event) {
        if (event.key === 'Escape' && !this.panelTarget.hidden) {
            event.stopPropagation();
            this.close();
        }
    }

    async build() {
        const form = this.element.closest('form');
        if (!form || !this.row) return;
        const body = new FormData();
        const keep = (el) => el.name && !el.disabled && !(['checkbox', 'radio'].includes(el.type) && !el.checked);
        let index = null;
        this.row.querySelectorAll('[name]').forEach((el) => {
            const match = /^tags\[([^\]]+)\]/.exec(el.name);
            if (match && index === null) index = match[1];
            if (keep(el)) body.append(el.name, el.value);
        });
        form.querySelectorAll('[name^="variables["]').forEach((el) => { if (keep(el)) body.append(el.name, el.value); });
        ['_csrf_token', 'site'].forEach((name) => {
            const field = form.querySelector(`[name="${name}"]`);
            if (field) body.append(name, field.value);
        });
        body.append('snippet_tag', index ?? '0');
        const label = this.buttonTarget.textContent;
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = 'Preparing…';
        let answer = { error: 'We couldn\'t build the snippet. Check the tag\'s trigger and variables, then try again.' };
        try {
            const response = await fetch(this.urlValue, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            answer = await response.json();
        } catch (e) { /* the default message above is shown */ }
        this.buttonTarget.disabled = false;
        this.buttonTarget.textContent = label;
        this.show(answer);
    }

    show(answer) {
        const reason = answer.error || answer.reason || '';
        this.reasonTarget.textContent = reason;
        this.reasonTarget.hidden = reason === '';
        const snippet = reason ? '' : (answer.snippet || '');
        this.codeTarget.textContent = snippet;
        this.codeTarget.hidden = snippet === '';
        this.copyTarget.hidden = snippet === '';
        this.attentionTarget.replaceChildren(...(answer.attention || []).map((text) => {
            const item = document.createElement('li');
            item.textContent = text;
            return item;
        }));
        this.attentionTarget.closest('[data-attention-box]').hidden = !(answer.attention || []).length;
        this.staleTarget.hidden = true;
        this.panelTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.headingTarget.focus();
    }

    async copy() {
        const text = this.codeTarget.textContent;
        try {
            await navigator.clipboard.writeText(text);
            this.announce('Snippet copied', 'Copied');
        } catch (e) {
            const range = document.createRange();
            range.selectNodeContents(this.codeTarget);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            let done = false;
            try { done = document.execCommand('copy'); } catch (err) { done = false; }
            if (done) this.announce('Snippet copied', 'Copied');
            else this.announce('Copy didn\'t work. Press Ctrl+C to copy the selected text.', null);
        }
    }

    announce(message, label) {
        this.liveTarget.textContent = message;
        if (label === null) return;
        const original = this.copyTarget.dataset.label || this.copyTarget.textContent;
        this.copyTarget.dataset.label = original;
        this.copyTarget.textContent = label;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.copyTarget.textContent = original; this.liveTarget.textContent = ''; }, 3000);
    }
}
