'use strict';

(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root && root.document) {
        root.XmrPayNodeSettings = api;
        const start = () => api.mount(root.document);
        if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', start);
        else start();
    }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    function boolValue(value) {
        if (typeof value === 'boolean') return value;
        return ['1', 'true', 'yes', 'on'].includes(String(value == null ? '' : value).trim().toLowerCase());
    }

    function normalizeRow(row) {
        if (typeof row === 'string') row = { url: row, auth: 'none' };
        row = row && typeof row === 'object' && !Array.isArray(row) ? row : {};
        let auth = String(row.auth || 'none').trim().toLowerCase();
        if (!['none', 'basic', 'digest'].includes(auth)) auth = 'none';
        return {
            url: String(row.url || '').trim().replace(/\/+$/, ''),
            auth,
            username: auth === 'none' ? '' : String(row.username || ''),
            password: auth === 'none' ? '' : String(row.password || ''),
            allow_insecure_http: auth === 'none' ? false : boolValue(row.allow_insecure_http),
        };
    }

    function parseNodes(value) {
        let rows = [];
        const text = String(value == null ? '' : value).trim();
        if (text) {
            if (text[0] === '[' || text[0] === '{') {
                try {
                    const decoded = JSON.parse(text);
                    rows = Array.isArray(decoded) ? decoded : [decoded];
                } catch {
                    rows = text.split(/[\r\n,]+/).filter(Boolean);
                }
            } else {
                rows = text.split(/[\r\n,]+/).filter(Boolean);
            }
        }
        rows = rows.map(normalizeRow);
        return rows.length ? rows : [normalizeRow({})];
    }

    function serializeNodes(rows) {
        const normalized = (Array.isArray(rows) ? rows : []).map(normalizeRow).filter(row => row.url !== '');
        return normalized.length ? JSON.stringify(normalized) : '';
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function field(labelText, control) {
        const label = element('label', 'xp-node-field');
        label.append(element('span', 'xp-node-label', labelText), control);
        return label;
    }

    function card(row, index, onChange, onRemove) {
        const article = element('article', 'xp-node-card');
        const head = element('div', 'xp-node-head');
        const title = element('div', 'xp-node-title');
        title.append(element('span', 'xp-node-index', String(index + 1)), element('strong', '', 'Monero node'));
        const status = element('span', 'xp-node-status', 'Saved, not checked');
        status.setAttribute('aria-live', 'polite');
        head.append(title, status);

        const url = element('input', 'form-control');
        url.type = 'url';
        url.placeholder = 'https://node.example:18081';
        url.value = row.url;
        url.autocomplete = 'url';

        const auth = element('select', 'form-select');
        for (const [value, text] of [['none', 'No authentication'], ['basic', 'HTTP Basic'], ['digest', 'HTTP Digest']]) {
            const option = element('option', '', text);
            option.value = value;
            option.selected = row.auth === value;
            auth.append(option);
        }

        const username = element('input', 'form-control');
        username.type = 'text';
        username.value = row.username;
        username.autocomplete = 'username';

        const passwordWrap = element('div', 'xp-password');
        const password = element('input', 'form-control');
        password.type = 'password';
        password.value = row.password;
        password.autocomplete = 'new-password';
        const reveal = element('button', 'xp-reveal', 'Show');
        reveal.type = 'button';
        reveal.setAttribute('aria-pressed', 'false');
        reveal.addEventListener('click', () => {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            reveal.textContent = show ? 'Hide' : 'Show';
            reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
        passwordWrap.append(password, reveal);

        const credentials = element('div', 'xp-node-credentials');
        credentials.append(field('Username', username), field('Password', passwordWrap));

        const insecureLabel = element('label', 'xp-insecure');
        const insecure = element('input');
        insecure.type = 'checkbox';
        insecure.checked = row.allow_insecure_http;
        insecureLabel.append(insecure, element('span', '', 'Allow credentials over HTTP on this trusted local network'));

        const remove = element('button', 'xp-remove', 'Remove');
        remove.type = 'button';
        remove.addEventListener('click', () => onRemove(article));

        const refresh = () => {
            const protectedNode = auth.value !== 'none';
            credentials.hidden = !protectedNode;
            insecureLabel.hidden = !protectedNode;
            article.classList.toggle('is-protected', protectedNode);
            onChange();
        };
        for (const control of [url, auth, username, password, insecure]) {
            control.addEventListener(control === auth || control === insecure ? 'change' : 'input', refresh);
        }

        const body = element('div', 'xp-node-grid');
        body.append(field('Node URL', url), field('Authentication', auth));
        article.append(head, body, credentials, insecureLabel, remove);
        article._xmrpay = {
            status,
            value: () => normalizeRow({
                url: url.value,
                auth: auth.value,
                username: username.value,
                password: password.value,
                allow_insecure_http: insecure.checked,
            }),
            setIndex: value => { title.querySelector('.xp-node-index').textContent = String(value + 1); },
            setRemoveVisible: value => { remove.hidden = !value; },
        };
        refresh();
        return article;
    }

    function mountTextarea(textarea) {
        if (textarea.dataset.xmrpayNodes === 'mounted') return;
        textarea.dataset.xmrpayNodes = 'mounted';

        const shell = element('section', 'xp-nodes');
        const intro = element('div', 'xp-nodes-intro');
        const copy = element('div');
        copy.append(element('h3', '', 'Monero nodes'), element('p', '', 'Add nodes in priority order. Authentication stays on this server.'));
        const add = element('button', 'xp-add', '+ Add another node');
        add.type = 'button';
        intro.append(copy, add);
        const list = element('div', 'xp-node-list');
        const note = element('p', 'xp-node-note', 'Every configured node is checked when you save. An unavailable secondary produces a warning, but does not block saving.');
        shell.append(intro, list, note);
        textarea.hidden = true;
        textarea.insertAdjacentElement('afterend', shell);

        const sync = () => {
            textarea.value = serializeNodes(Array.from(list.children).map(node => node._xmrpay.value()));
            const many = list.children.length > 1;
            Array.from(list.children).forEach((node, index) => {
                node._xmrpay.setIndex(index);
                node._xmrpay.setRemoveVisible(many);
            });
        };
        const append = row => {
            const node = card(row, list.children.length, sync, target => {
                target.remove();
                if (!list.children.length) append(normalizeRow({}));
                sync();
            });
            list.append(node);
            sync();
        };
        parseNodes(textarea.value).forEach(append);
        add.addEventListener('click', () => {
            append(normalizeRow({}));
            const newest = list.lastElementChild;
            newest.querySelector('input[type="url"]').focus();
        });

        const form = textarea.closest('form');
        if (form) form.addEventListener('submit', () => {
            sync();
            const started = Date.now();
            const statuses = Array.from(list.children).map(node => node._xmrpay.status);
            const timer = setInterval(() => {
                const elapsed = ((Date.now() - started) / 1000).toFixed(1);
                statuses.forEach(item => {
                    item.textContent = 'Checking ' + elapsed + 's';
                    item.classList.add('is-checking');
                });
            }, 100);
            const timeoutInput = form.querySelector('input[name*="[http_timeout]"], input[name*="[xmr_http_timeout]"]');
            const seconds = Math.min(60, Math.max(2, Number(timeoutInput && timeoutInput.value) || 20));
            setTimeout(() => {
                clearInterval(timer);
                statuses.forEach(item => { item.textContent = 'Waiting for server'; });
            }, seconds * 1000 * Math.max(1, statuses.length) + 1000);
        });
    }

    function mount(doc) {
        const selector = 'textarea[name*="[nodes]"], textarea[name*="[xmr_nodes]"], textarea[id*="xmr_nodes"]';
        Array.from(doc.querySelectorAll(selector)).forEach(mountTextarea);
    }

    return { normalizeRow, parseNodes, serializeNodes, mount };
});
