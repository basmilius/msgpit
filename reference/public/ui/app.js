import {renderMarkdown} from '/ui/markdown.js';
import {renderRawMessage} from '/ui/rawmessage.js';
import {renderHtmlSource} from '/ui/htmlsource.js';

const FALLBACK_POLL_MS = 2000;

/** A drag only keeps firing while it is over the window, so silence means it ended elsewhere. */
const DRAG_IDLE_MS = 400;

/**
 * The url says what you are looking at, so a refresh lands you back there: which message, which
 * tab, or which reference page. A bare tab name is still understood, since that is what the
 * earlier links looked like.
 *
 * @return {{message: ?string, tab: ?string, doc: ?string}}
 */
function readHash() {
    const hash = decodeURIComponent(location.hash.slice(1));

    if (hash.startsWith('docs/')) {
        return {message: null, tab: null, doc: hash.slice('docs/'.length)};
    }

    if (hash.startsWith('m/')) {
        const [id, tab] = hash.slice(2).split('/');

        return {message: id || null, tab: tab || null, doc: null};
    }

    return {message: null, tab: /^[a-z]+$/.test(hash) ? hash : null, doc: null};
}
const SEGMENT_LIMITS = {'GSM-7': {single: 160, concatenated: 153}, 'UCS-2': {single: 70, concatenated: 67}};

const el = {
    messages: document.getElementById('messages'),
    empty: document.getElementById('empty'),
    detail: document.getElementById('detail'),
    search: document.getElementById('search'),
    scenario: document.getElementById('scenario'),
    clear: document.getElementById('clear'),
    markRead: document.getElementById('mark-read'),
    notify: document.getElementById('notify'),
    navAll: document.getElementById('nav-all'),
    navProviders: document.getElementById('nav-providers'),
    navChannels: document.getElementById('nav-channels'),
    navDocs: document.getElementById('nav-docs'),
    docsToggle: document.getElementById('docs-toggle'),
    docs: document.getElementById('docs'),
    workspace: document.querySelector('.workspace'),
    statMessages: document.getElementById('stat-messages'),
    statSegments: document.getElementById('stat-segments'),
    statRecipients: document.getElementById('stat-recipients'),
    brand: document.getElementById('brand'),
    connection: document.getElementById('connection'),
    import: document.getElementById('import'),
    importInput: document.getElementById('import-input'),
    dropzone: document.getElementById('dropzone'),
    dropzoneNote: document.getElementById('dropzone-note'),
    version: document.getElementById('version'),
};

/**
 * Where the url pointed when the page loaded. Read once: from here on the app writes the url, so
 * reading it again would only return what we just put there.
 */
const opened = readHash();

const state = {
    messages: [],
    selectedId: null,
    // Any tab name is accepted here; renderDetail falls back when the message has no such tab,
    // so this list cannot fall behind the tabs themselves.
    tab: opened.tab ?? 'message',
    filter: {provider: '', channel: ''},
    search: '',
    signature: '',
    // A url that names a message counts as a choice, so refresh() will not auto-open the newest.
    touched: opened.message !== null || opened.doc !== null,
    doc: null,
    mailBody: 'html',
    rawView: 'structured',
    sourceView: 'formatted',
    sourcePane: 'message',
    linkResults: {},
    // Reports with the DNS checks filled in, per message. Run when the tab is opened rather than
    // when the message is, so reading your post never waits on a resolver.
    authResults: {},
    authFailed: {},
    checkingLinks: false,
    endpoints: {providers: [], smtp: null},
    scenarios: [],
    unread: 0,
    dlrProviders: [],
};

const api = async (path, options = {}) => {
    const response = await fetch(`/api${path}`, {headers: {'Content-Type': 'application/json'}, ...options});

    if (!response.ok) {
        throw new Error(`${options.method ?? 'GET'} /api${path} failed: ${response.status}`);
    }

    return response.status === 204 ? null : response.json();
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => (
    {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]
));

// Text context only, so quotes survive for the JSON highlighter to key on.
const escapeText = (value) => String(value ?? '').replace(/[&<>]/g, (char) => (
    {'&': '&amp;', '<': '&lt;', '>': '&gt;'}[char]
));

const formatTime = (iso) => new Date(iso).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'});

const formatDateTime = (iso) => new Date(iso).toLocaleString([], {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit',
});

const plural = (count, noun) => `${count} ${noun}${count === 1 ? '' : 's'}`;

/** Mirrors Core\Segments: one character outside GSM-7 pushes the whole message to UCS-2. */
const highlightBody = (body, ucs2Offsets = []) => {
    const offsets = new Set(ucs2Offsets);

    return [...body]
        .map((char, index) => (offsets.has(index)
            ? `<mark class="ucs2" title="Forces UCS-2 encoding">${escapeHtml(char)}</mark>`
            : escapeHtml(char)))
        .join('');
};

/** One bar per segment, each filled to how much of its capacity is used. */
const renderMeter = (message) => {
    const limits = SEGMENT_LIMITS[message.encoding];

    if (!limits || !message.segments) {
        return '';
    }

    const capacity = message.segments === 1 ? limits.single : limits.concatenated;
    const total = message.segments * capacity;
    const ucs2 = message.encoding === 'UCS-2';

    const parts = Array.from({length: message.segments}, (unused, index) => {
        const used = Math.max(0, Math.min(capacity, message.units - index * capacity));

        return `<span class="meter-part${ucs2 ? ' ucs2' : ''}">
            <span class="meter-fill" style="width: ${(used / capacity) * 100}%"></span>
        </span>`;
    }).join('');

    return `
        <div class="meter">${parts}</div>
        <p class="meter-caption">
            ${escapeHtml(message.encoding)} &middot; ${plural(message.segments, 'segment')} &middot;
            ${message.units} of ${total} ${ucs2 ? 'code units' : 'septets'} used &middot;
            ${plural(message.characters, 'character')}
        </p>
    `;
};

/** Colours an already escaped JSON string; keys and values get their own token class. */
const highlightJson = (json) => json.replace(
    /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)/g,
    (match) => {
        if (/^"/.test(match)) {
            return `<span class="tok-${/:$/.test(match) ? 'key' : 'string'}">${match}</span>`;
        }

        return `<span class="tok-${/true|false|null/.test(match) ? 'literal' : 'number'}">${match}</span>`;
    },
);

/** Splits the stored request into headers and body, pretty-printing the body when it is JSON. */
const renderRaw = (rawRequest) => {
    const separator = rawRequest.indexOf('\n\n');
    const head = separator === -1 ? rawRequest : rawRequest.slice(0, separator);
    const body = separator === -1 ? '' : rawRequest.slice(separator + 2);

    if (body.trim() === '') {
        return `<pre>${escapeHtml(head)}</pre>`;
    }

    try {
        const formatted = JSON.stringify(JSON.parse(body), null, 2);

        return `<pre>${escapeHtml(head)}\n\n<span class="json">${highlightJson(escapeText(formatted))}</span></pre>`;
    } catch {
        // Not JSON (form encoded, XML, whatever the provider takes): show it untouched.
        return `<pre>${escapeHtml(rawRequest)}</pre>`;
    }
};

/** Pretty-prints a standalone JSON string, leaving anything else alone. */
const formatJson = (value) => {
    try {
        return highlightJson(escapeText(JSON.stringify(JSON.parse(value), null, 2)));
    } catch {
        return escapeHtml(value);
    }
};

const visibleMessages = () => state.messages.filter((message) => {
    if (state.filter.provider && message.provider !== state.filter.provider) {
        return false;
    }

    if (state.filter.channel && message.channel !== state.filter.channel) {
        return false;
    }

    const needle = state.search.toLowerCase();

    return needle === ''
        || message.to.toLowerCase().includes(needle)
        || message.body.toLowerCase().includes(needle);
});

const renderSidebar = () => {
    const countBy = (key) => state.messages.reduce((totals, message) => {
        const tally = totals[message[key]] ?? {total: 0, unread: 0};

        return {
            ...totals,
            [message[key]]: {total: tally.total + 1, unread: tally.unread + (message.read ? 0 : 1)},
        };
    }, {});

    const item = (label, tally, type, value) => `
        <li>
            <button type="button" data-filter="${type}" data-value="${escapeHtml(value)}"
                    aria-current="${state.filter[type] === value}">
                <span>${escapeHtml(label)}</span>
                <span class="count">
                    ${tally.unread > 0 ? `<span class="unread-count">${tally.unread}</span>` : ''}
                    ${tally.total}
                </span>
            </button>
        </li>
    `;

    el.navAll.innerHTML = `
        <li>
            <button type="button" data-filter="all" data-value=""
                    aria-current="${!state.filter.provider && !state.filter.channel}">
                <span>All messages</span>
                <span class="count">
                    ${state.unread > 0 ? `<span class="unread-count">${state.unread}</span>` : ''}
                    ${state.messages.length}
                </span>
            </button>
        </li>
    `;

    const providers = countBy('provider');
    const channels = countBy('channel');

    el.navProviders.innerHTML = Object.entries(providers)
        .map(([name, tally]) => item(name, tally, 'provider', name))
        .join('') || '<li><button type="button" disabled><span>None yet</span></button></li>';

    el.navChannels.innerHTML = Object.entries(channels)
        .map(([name, tally]) => item(name, tally, 'channel', name))
        .join('') || '<li><button type="button" disabled><span>None yet</span></button></li>';
};

/** The unread count belongs in the title too: the tab is often the only thing you can see. */
const renderTitle = () => {
    document.title = state.unread > 0 ? `(${state.unread}) msgpit` : 'msgpit';
};

const renderStats = () => {
    const messages = visibleMessages();
    const segments = messages.reduce((total, message) => total + (message.segments ?? 0), 0);
    const recipients = new Set(messages.map((message) => message.to)).size;

    renderTitle();
    el.statMessages.textContent = plural(messages.length, 'message');
    el.statSegments.textContent = plural(segments, 'segment');
    el.statRecipients.textContent = plural(recipients, 'recipient');
};

/**
 * What to point an application at, taken from the running instance rather than written down here:
 * every enabled provider with its own base url, plus smtp when it is listening.
 */
const renderEmptyState = () => {
    const rows = state.endpoints.providers
        .map((provider) => [provider.id, provider.baseUrl])
        .concat(state.endpoints.smtp
            ? [['email', `smtp://${state.endpoints.smtp.host}:${state.endpoints.smtp.port}`]]
            : []);

    el.empty.innerHTML = rows.length === 0
        ? 'Nothing captured yet.'
        : `
            <span class="empty-title">Nothing captured yet.</span>
            <span>Point your application at one of these and send something.</span>
            <span class="endpoints">
                ${rows.map(([label, url]) => `
                    <span class="endpoint">
                        <span class="tag">${escapeHtml(label)}</span>
                        <code>${escapeHtml(url)}</code>
                    </span>
                `).join('')}
            </span>
            <span class="empty-or">or drop a .eml file anywhere on this page</span>
        `;
};

/**
 * Who a message went to, short enough to leave room for what it says. An import holds every
 * address in one field, and spelling all three out fills the line before the body starts.
 */
const recipientLabel = (message) => {
    const first = String(message.to ?? '').split(',')[0].trim();
    const more = (message.meta.recipients ?? 1) - 1;

    return more > 0 ? `${first} +${more}` : first;
};

const renderList = () => {
    const messages = visibleMessages();

    el.empty.hidden = messages.length > 0;

    if (messages.length === 0) {
        renderEmptyState();
    }

    el.messages.innerHTML = messages.map((message) => `
        <li data-id="${escapeHtml(message.id)}" aria-selected="${message.id === state.selectedId}"
            class="${message.read ? '' : 'unread'}">
            <div class="list-head">
                <span class="to">${escapeHtml(message.channel === 'email' ? (message.meta.subject || '(no subject)') : message.to)}</span>
                <time datetime="${escapeHtml(message.createdAt)}">${formatTime(message.createdAt)}</time>
            </div>
            ${message.channel === 'email' ? `<p class="recipient">${escapeHtml(recipientLabel(message))}</p>` : ''}
            <p class="preview">${escapeHtml(message.body) || '<em>empty</em>'}</p>
            <div class="tags">
                <span class="tag${message.meta.imported ? ' imported' : ''}">${message.meta.imported ? 'imported' : escapeHtml(message.provider)}</span>
                <span class="tag">${escapeHtml(message.channel)}</span>
                ${message.segments ? `<span class="tag${message.encoding === 'UCS-2' ? ' encoding-ucs2' : ''}">${escapeHtml(message.encoding)} &middot; ${message.segments}</span>` : ''}
                <span class="tag status-${escapeHtml(message.status)}">${escapeHtml(message.status)}</span>
            </div>
        </li>
    `).join('');
};

const formatBytes = (bytes) => (bytes < 1024
    ? `${bytes} B`
    : (bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} kB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`));

const partUrl = (message, part) => `/api/messages/${message.id}/parts/${part.id}`;

/**
 * The html as the recipient would see it, rendered in a sandboxed iframe: no scripts, no forms,
 * and its own origin, so a captured mail cannot touch msgpit. Images the mail carries are
 * referenced by cid, which means nothing to a browser, so those become part URLs first.
 */
const renderMailPreview = (message) => {
    const inline = (message.parts ?? []).filter((part) => part.contentId);

    const html = inline.reduce(
        (carry, part) => carry.replaceAll(`cid:${part.contentId}`, `${location.origin}${partUrl(message, part)}`),
        message.html,
    );

    // A browser with no stylesheet falls back to Times, which no mail client does: they all apply
    // a default of their own. Set one here too, as a starting point the message overrides the
    // moment it says anything about type itself.
    const base = `<style>
        html { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
               font-size: 14px; line-height: 1.5; color: #1a1a1a; background: #fff; }
    </style>`;

    return `<iframe class="mail-preview" sandbox="allow-popups" referrerpolicy="no-referrer"
                    title="Message preview" srcdoc="${escapeHtml(base + html)}"></iframe>`;
};

const mailHeaders = (message) => {
    const meta = message.meta;

    const rows = [
        ['From', message.from],
        ['To', meta.to ?? message.to],
        ['Cc', meta.cc],
        ['Reply-To', meta.replyTo],
        // Nothing was delivered for an import, so the row says where the addresses came from.
        [meta.imported ? 'Recipients' : 'Delivered to', meta.to === message.to ? null : message.to],
        ['Captured', formatDateTime(message.createdAt)],
    ].filter(([, value]) => value);

    return `<dl class="fields">${rows.map(([label, value]) => `
        <dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd>
    `).join('')}</dl>`;
};

/**
 * Which body to show. A mail usually carries both, and the plain text alternative is the one
 * people forget to keep in step, so switching has to be one click rather than a different tab.
 */
const bodyToggle = (message) => {
    if (message.html === null || message.text === null) {
        return '';
    }

    const option = (value, label) => `
        <button type="button" data-body="${value}" aria-pressed="${state.mailBody === value}">${label}</button>
    `;

    return `<div class="switch" role="group" aria-label="Message format">
        ${option('html', 'HTML')}${option('text', 'Plain text')}
    </div>`;
};

const SECTIONS = {
    spam: 'Spam filters',
    authentication: 'Authentication',
    reputation: 'Reputation',
    content: 'Message content',
    headers: 'Headers',
    links: 'Links',
};

const STATUS_LABEL = {pass: 'ok', warn: 'could be better', fail: 'problem', skip: 'not applicable'};

/**
 * One finding. The evidence is what makes it usable: "no List-Unsubscribe" is an opinion, the
 * header that was actually read is a fact, and only the second one says where to look.
 */
const renderFinding = (finding) => `
    <li class="finding-row status-${escapeHtml(finding.status)}">
        <div class="finding-head">
            <span class="finding-mark" title="${escapeHtml(STATUS_LABEL[finding.status] ?? finding.status)}"></span>
            <span class="finding-title">${escapeHtml(finding.title)}</span>
            ${finding.penalty > 0 ? `<span class="finding-penalty">&minus;${finding.penalty.toFixed(1)}</span>` : ''}
        </div>
        ${finding.explanation ? `<p class="finding-why">${escapeHtml(finding.explanation)}</p>` : ''}
        ${finding.evidence.length > 0
            ? `<details class="finding-evidence">
                   <summary>${finding.evidence.length} ${finding.evidence.length === 1 ? 'detail' : 'details'}</summary>
                   <pre>${finding.evidence.map(escapeHtml).join('\n')}</pre>
               </details>`
            : ''}
    </li>
`;

const mailPanels = {
    /**
     * What a receiving server is likely to hold against this message, drawn together from the
     * other tabs. Not a prediction: real filters weigh reputation and sending history that
     * nothing here can see.
     */
    report: (message) => {
        const report = state.authResults[message.id] ?? message.report;
        const tone = verdict(report.score >= 8, report.score >= 6);
        const sections = Object.entries(SECTIONS)
            .map(([id, title]) => [title, report.findings.filter((finding) => finding.section === id)])
            .filter(([, findings]) => findings.length > 0);

        return `
            <div class="score-head">
                <div class="score score-${tone}">
                    <strong>${report.score.toFixed(1)}</strong>
                    <span>of ${report.max}</span>
                </div>
                <p>
                    ${report.passed} of ${report.applicable} checks passed.
                    ${report.skipped > 0
                        ? `${report.skipped} ${report.skipped === 1 ? 'check does' : 'checks do'} not apply to this message and are left out of the score.`
                        : ''}
                    <br>
                    <span class="score-caveat">Computed from the message alone. A real filter also weighs reputation and history.</span>
                </p>
            </div>
            ${sections.map(([title, findings]) => `
                <h3>${escapeHtml(title)}</h3>
                ${title === SECTIONS.authentication && !state.authResults[message.id] ? `
                    <p class="check-prompt">
                        ${state.authFailed[message.id]
                            ? 'Those lookups did not come back. <button type="button" class="ghost" data-authentication>Try again</button>'
                            : 'Asking DNS what the sender\'s own zone says. Nothing leaves the network beyond those lookups.'}
                    </p>
                ` : ''}
                <ul class="findings">${findings.map(renderFinding).join('')}</ul>
            `).join('')}
        `;
    },

    message: (message) => {
        const showHtml = message.html !== null && (state.mailBody === 'html' || message.text === null);

        return `
            <h3>Headers</h3>
            ${mailHeaders(message)}
            <div class="body-head">
                ${bodyToggle(message)}
                <h3>${showHtml ? 'As the recipient sees it' : 'Plain text'}</h3>
            </div>
            ${showHtml
                ? renderMailPreview(message)
                : `<p class="body-text">${escapeHtml(message.text ?? message.body) || '<em>empty</em>'}</p>`}
        `;
    },

    /**
     * Every header, not the handful the summary shows. This is where mail analysis actually
     * happens: a missing Date, a Return-Path that disagrees with From, a List-Unsubscribe that
     * never made it in.
     */
    headers: (message) => {
        const headers = message.headers ?? {};
        const names = Object.keys(headers);

        if (names.length === 0) {
            return '<p class="empty">No headers were recorded.</p>';
        }

        // The ones people look for first, in the order they expect them.
        const order = ['from', 'to', 'cc', 'bcc', 'reply-to', 'return-path', 'subject', 'date', 'message-id'];
        const sorted = [
            ...order.filter((name) => name in headers),
            ...names.filter((name) => !order.includes(name)).sort(),
        ];

        return `
            <h3>${plural(names.length, 'header')}</h3>
            <div class="table-scroll">
                <table class="headers">
                    <tbody>
                        ${sorted.map((name) => `
                            <tr>
                                <td class="header-name">${escapeHtml(name)}</td>
                                <td class="header-value">${escapeHtml(headers[name])}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    },

    links: (message) => {
        const results = state.linkResults[message.id];

        if (!results) {
            return `
                <h3>${plural(message.links.length, 'link')} in this message</h3>
                <ul class="findings">
                    ${message.links.map((link) => `
                        <li>
                            <div class="finding">
                                <span class="tag">${escapeHtml(link.kind)}</span>
                                <span class="link-url">${escapeHtml(link.url)}</span>
                            </div>
                        </li>
                    `).join('')}
                </ul>
                <div class="notice">
                    <p><strong>Checking these fetches them for real.</strong> It is the only thing
                    msgpit does that leaves your machine. Links in mail often carry a one-shot
                    token, so fetching a password reset or an unsubscribe link can spend it, and a
                    tracking pixel will count the fetch as somebody reading the message.</p>
                    <button type="button" class="primary" id="check-links" ${state.checkingLinks ? 'disabled' : ''}>
                        ${state.checkingLinks ? 'Checking...' : 'Check these links'}
                    </button>
                </div>
            `;
        }

        const row = (link) => {
            const failed = link.status === null || link.status >= 400;
            const redirected = link.status !== null && link.status >= 300 && link.status < 400;

            return `
                <li>
                    <div class="finding">
                        <span class="tag ${failed ? 'status-failed' : (redirected ? '' : 'status-delivered')}">
                            ${link.status ?? 'failed'}
                        </span>
                        <span class="tag">${escapeHtml(link.kind)}</span>
                        <span class="link-url">${escapeHtml(link.url)}</span>
                    </div>
                    ${link.redirect ? `<p class="link-note">redirects to ${escapeHtml(link.redirect)}</p>` : ''}
                    ${link.reason ? `<p class="link-note">${escapeHtml(link.reason)}</p>` : ''}
                </li>
            `;
        };

        const broken = results.filter((link) => link.status === null || link.status >= 400);

        return `
            <h3>${broken.length === 0 ? 'Every link answered' : `${plural(broken.length, 'link')} did not answer`}</h3>
            <ul class="findings links">${results.map(row).join('')}</ul>
            <p class="muted source">
                <button type="button" class="ghost" id="check-links">Check again</button>
            </p>
        `;
    },

    /** A ring showing how the three verdicts divide the tested clients. */
    html: (message) => {
        const check = message.htmlCheck;
        const circumference = 2 * Math.PI * 54;
        let offset = 0;

        // A hair of track between the segments: three arcs meeting edge to edge read as one shape
        // however far apart their colours are.
        const gap = circumference * 0.006;

        const arc = (share, className) => {
            const length = share / 100 * circumference;
            const drawn = Math.max(0, length - gap);
            const segment = `<circle class="${className}" cx="64" cy="64" r="54" fill="none" stroke-width="16"
                stroke-dasharray="${drawn} ${circumference - drawn}" stroke-dashoffset="${-offset}"></circle>`;
            offset += length;

            return segment;
        };

        const bar = (finding) => {
            const share = (value) => (finding.tested === 0 ? 0 : value / finding.tested * 100);

            return `
                <li>
                    <div class="finding">
                        <span class="finding-title">${escapeHtml(finding.title)}</span>
                        <span class="tag">${escapeHtml(finding.category)}</span>
                        ${finding.occurrences > 1 ? `<span class="finding-count">&times;${finding.occurrences}</span>` : ''}
                    </div>
                    <div class="support" title="${finding.supported} yes, ${finding.partial} partial, ${finding.unsupported} no">
                        <span class="yes" style="width:${share(finding.supported)}%"></span>
                        <span class="partly" style="width:${share(finding.partial)}%"></span>
                        <span class="no" style="width:${share(finding.unsupported)}%"></span>
                    </div>
                </li>
            `;
        };

        return `
            <div class="html-check">
                <svg class="ring" viewBox="0 0 128 128" aria-hidden="true">
                    <circle cx="64" cy="64" r="54" fill="none" stroke-width="16" class="track"></circle>
                    ${arc(check.supported, 'yes')}${arc(check.partial, 'partly')}${arc(check.unsupported, 'no')}
                    <text x="64" y="60" text-anchor="middle" class="ring-value">${check.supported.toFixed(1)}%</text>
                    <text x="64" y="78" text-anchor="middle" class="ring-label">support</text>
                </svg>
                <ul class="legend">
                    <li><span class="swatch yes"></span>${check.supported.toFixed(2)}% supported</li>
                    <li><span class="swatch partly"></span>${check.partial.toFixed(2)}% partially</li>
                    <li><span class="swatch no"></span>${check.unsupported.toFixed(2)}% not supported</li>
                    <li class="muted">${check.tested} results across ${plural(check.features, 'feature')}</li>
                </ul>
            </div>

            ${check.warnings.length === 0
                ? '<p class="empty">Everything this message uses is widely supported.</p>'
                : `<h3>${plural(check.warnings.length, 'feature')} worth checking</h3>
                   <ul class="findings">${check.warnings.map(bar).join('')}</ul>`}

            <p class="muted source">
                Based on compatibility data from
                <a href="https://www.caniemail.com" target="_blank" rel="noreferrer noopener">caniemail.com</a>${check.dataUpdated ? `, updated ${escapeHtml(check.dataUpdated.slice(0, 10))}` : ''}.
            </p>
        `;
    },

    spam: (message) => {
        const spam = message.meta.spam;
        const share = Math.max(0, Math.min(1, spam.score / Math.max(spam.threshold, 0.1)));

        const rows = spam.rules.map((rule) => `
            <tr class="${rule.points > 0 ? 'costly' : ''}">
                <td class="points">${rule.points > 0 ? '+' : ''}${rule.points.toFixed(1)}</td>
                <td class="rule">${escapeHtml(rule.name)}</td>
                <td>${escapeHtml(rule.description)}</td>
            </tr>
        `).join('');

        return `
            <h3>Score</h3>
            <p class="spam-score ${spam.spam ? 'is-spam' : ''}">
                <strong>${spam.score.toFixed(1)}</strong>
                <span>of ${spam.threshold.toFixed(1)}</span>
                <span class="tag ${spam.spam ? 'status-failed' : 'status-delivered'}">
                    ${spam.spam ? 'would be marked as spam' : 'would pass'}
                </span>
            </p>
            <div class="meter">
                <span class="meter-part${spam.spam ? ' ucs2' : ''}">
                    <span class="meter-fill" style="width: ${share * 100}%"></span>
                </span>
            </div>
            ${spam.rules.length === 0
                ? '<p class="empty">No rules fired.</p>'
                : `<h3>What it reacted to</h3>
                   <div class="table-scroll"><table class="rules"><tbody>${rows}</tbody></table></div>`}
        `;
    },

    attachments: (message) => {
        const attachments = (message.parts ?? []).filter((part) => part.disposition === 'attachment');

        return `
            <h3>${plural(attachments.length, 'attachment')}</h3>
            <ul class="attachments">
                ${attachments.map((part) => `
                    <li>
                        <a href="${partUrl(message, part)}?download=1" download>
                            <span class="attachment-name">${escapeHtml(part.filename ?? 'unnamed')}</span>
                            <span class="attachment-meta">${escapeHtml(part.contentType)} &middot; ${formatBytes(part.size)}</span>
                        </a>
                    </li>
                `).join('')}
            </ul>
        `;
    },
};

const panels = {
    message: (message) => `
        <h3>Body</h3>
        <p class="body-text">${highlightBody(message.body, message.ucs2Offsets) || '<em>empty</em>'}</p>
        ${message.segments ? `<h3>Segments</h3>${renderMeter(message)}` : ''}
        <h3>Routing</h3>
        <dl class="fields">
            <dt>From</dt><dd>${escapeHtml(message.from ?? '-')}</dd>
            <dt>To</dt><dd>${escapeHtml(message.to)}</dd>
            <dt>Provider reference</dt><dd>${escapeHtml(message.providerRef ?? '-')}</dd>
            <dt>Batch</dt><dd>${escapeHtml(message.batchId)}</dd>
            <dt>Captured</dt><dd>${formatDateTime(message.createdAt)}</dd>
        </dl>
    `,

    /**
     * What the message is made of: the message as it arrived, and the html as the sender wrote it,
     * which is not always what the preview suggests. One tab with two views rather than two tabs,
     * because it is one question asked twice: what is actually in here.
     */
    raw: (message) => {
        if (!isMail(message)) {
            return `<h3>Request as received</h3>${renderRaw(message.rawRequest ?? '')}`;
        }

        const html = message.html !== null;
        const showHtml = html && state.sourcePane === 'html';

        return `
            <div class="body-head">
                ${html ? `<div class="switch" role="group" aria-label="Source">
                    <button type="button" data-pane="message" aria-pressed="${!showHtml}">Message</button>
                    <button type="button" data-pane="html" aria-pressed="${showHtml}">HTML</button>
                </div>` : ''}
                ${showHtml
                    ? `<div class="switch subtle" role="group" aria-label="HTML layout">
                           <button type="button" data-source="formatted" aria-pressed="${state.sourceView !== 'original'}">Formatted</button>
                           <button type="button" data-source="original" aria-pressed="${state.sourceView === 'original'}">Original</button>
                       </div>`
                    : `<div class="switch subtle" role="group" aria-label="Message layout">
                           <button type="button" data-raw="structured" aria-pressed="${state.rawView !== 'plain'}">Structured</button>
                           <button type="button" data-raw="plain" aria-pressed="${state.rawView === 'plain'}">Plain</button>
                       </div>`}
                <h3>${showHtml ? 'HTML source' : 'Message as received'}</h3>
            </div>
            ${showHtml
                ? `<pre class="source-html code">${renderHtmlSource(message.html ?? '', {format: state.sourceView !== 'original'})}</pre>`
                : (state.rawView !== 'plain'
                    ? renderRawMessage(message.rawRequest ?? '')
                    : renderRaw(message.rawRequest ?? ''))}
        `;
    },

    delivery: (message) => `
        <h3>Report back to the app</h3>
        <div class="dlr-actions">
            <button type="button" class="primary" data-dlr="delivered">Mark delivered</button>
            <button type="button" class="ghost danger" data-dlr="failed">Mark failed</button>
        </div>
        ${message.deliveryReports.length === 0
            ? '<p class="empty">No delivery reports sent yet. Set MSGPIT_SPRYNG_DLR_URL to have msgpit call your app back.</p>'
            : message.deliveryReports.map((report) => `
                <div class="report">
                    <div class="report-head">
                        <span class="tag status-${escapeHtml(report.status)}">${escapeHtml(report.status)}</span>
                        <span class="url" title="${escapeHtml(report.url)}">${escapeHtml(report.url)}</span>
                        <span>${report.responseStatus ?? 'no response'} &middot; ${formatTime(report.sentAt)}</span>
                    </div>
                    <pre>${formatJson(report.responseBody ?? '')}</pre>
                </div>
            `).join('')}
    `,

    meta: (message) => `
        <h3>Provider extras</h3>
        ${Object.keys(message.meta).length === 0
            ? '<p class="empty">This request carried no extra fields.</p>'
            : `<pre>${escapeHtml(JSON.stringify(message.meta, null, 2))}</pre>`}
    `,
};

/** Delivery only makes sense for providers that can call the app back, so e-mail never gets the tab. */
const isMail = (message) => message.channel === 'email';

/** Green when it is fine, amber when it is worth a look, red when it is not. */
const verdict = (good, acceptable) => (good ? 'ok' : (acceptable ? 'warn' : 'bad'));

const tabsFor = (message) => {
    const tabs = [
        ['message', isMail(message) ? 'Preview' : 'Message', null],
        ['raw', isMail(message) ? 'Source' : 'Raw', null],
    ];

    if (isMail(message)) {
        // The order walks from what the message is to what is wrong with it: what it looks like,
        // what it is made of, and then the verdicts, with the conclusion of those first.
        tabs.push(['headers', 'Headers', Object.keys(message.headers ?? {}).length || null]);

        const report = state.authResults[message.id] ?? message.report;

        if (report) {
            tabs.push(['report', 'Deliverability', report.score.toFixed(1), verdict(report.score >= 8, report.score >= 6)]);
        }

        if (message.meta.spam) {
            const spam = message.meta.spam;

            // Half the threshold still leaves room; above it a real filter would act.
            tabs.push([
                'spam',
                'Spam',
                spam.score.toFixed(1),
                verdict(!spam.spam && spam.score < spam.threshold / 2, !spam.spam),
            ]);
        }

        if (message.htmlCheck) {
            const supported = message.htmlCheck.supported;

            tabs.push(['html', 'HTML check', `${Math.round(supported)}%`, verdict(supported >= 90, supported >= 70)]);
        }

        if ((message.links ?? []).length > 0) {
            const checked = state.linkResults[message.id];
            const broken = checked?.filter((link) => link.status === null || link.status >= 400).length;

            tabs.push([
                'links',
                'Links',
                checked ? `${broken}/${checked.length}` : message.links.length,
                checked ? verdict(broken === 0, broken === 0) : null,
            ]);
        }

        const attachments = (message.parts ?? []).filter((part) => part.disposition === 'attachment');

        if (attachments.length > 0) {
            tabs.push(['attachments', 'Attachments', attachments.length]);
        }
    }

    if (state.dlrProviders.includes(message.provider)) {
        tabs.push(['delivery', 'Delivery', message.deliveryReports.length || null]);
    }

    tabs.push(['meta', 'Meta', Object.keys(message.meta).length || null]);

    return tabs;
};

// Idempotent: renderDetail runs on every refresh, and this must ask DNS once per message.
const pendingAuthentication = new Set();

const loadAuthentication = async (message) => {
    if (state.authResults[message.id] || state.authFailed[message.id] || pendingAuthentication.has(message.id)) {
        return;
    }

    pendingAuthentication.add(message.id);

    try {
        const {report} = await api(`/messages/${message.id}/authentication`, {method: 'POST'});
        state.authResults = {...state.authResults, [message.id]: report};
    } catch {
        state.authFailed = {...state.authFailed, [message.id]: true};
    } finally {
        pendingAuthentication.delete(message.id);

        if (state.selectedId === message.id) {
            renderDetail(message);
        }
    }
};

const renderDetail = (message) => {
    const tabs = tabsFor(message);

    // A deep link or a previous message can point at a tab this message does not have.
    if (!tabs.some(([id]) => id === state.tab)) {
        state.tab = 'message';
    }

    const panelsFor = isMail(message) ? {...panels, ...mailPanels} : panels;
    const title = isMail(message) ? (message.meta.subject || '(no subject)') : message.to;

    el.detail.innerHTML = `
        <div class="detail-head">
            <h2>${escapeHtml(title)}</h2>
            <p class="subtitle">
                ${isMail(message) ? escapeHtml(recipientLabel(message)) : escapeHtml(message.provider)} &middot;
                ${escapeHtml(message.channel)} &middot;
                ${formatDateTime(message.createdAt)} &middot; ${escapeHtml(message.status)}
            </p>
            ${message.meta.imported ? `
                <p class="imported-note">
                    <span class="tag imported">imported</span>
                    ${message.meta.filename ? `Imported from <code>${escapeHtml(message.meta.filename)}</code>, so` : 'Imported, so'}
                    the addresses come from the headers rather than from an envelope.
                </p>
            ` : ''}
        </div>
        <div class="tabs" role="tablist">
            ${tabs.map(([id, label, count, tone]) => `
                <button type="button" role="tab" data-tab="${id}" aria-selected="${state.tab === id}">
                    <span class="tab-label" data-label="${escapeHtml(label)}">${escapeHtml(label)}</span>
                    ${count ? `<span class="count${tone ? ` ${tone}` : ''}">${count}</span>` : ''}
                </button>
            `).join('')}
        </div>
        <div class="panel" role="tabpanel">${(panelsFor[state.tab] ?? panelsFor.message)(message)}</div>
    `;

    keepTabsUsable();

    el.detail.querySelectorAll('[data-tab]').forEach((button) => {
        button.addEventListener('click', () => {
            state.tab = button.dataset.tab;
            writeHash();
            renderDetail(message);
        });
    });

    el.detail.querySelector('#check-links')?.addEventListener('click', async () => {
        state.checkingLinks = true;
        renderDetail(message);

        try {
            const {links} = await api(`/messages/${message.id}/links`, {method: 'POST'});
            state.linkResults = {...state.linkResults, [message.id]: links};
        } finally {
            state.checkingLinks = false;
            renderDetail(message);
        }
    });

    el.detail.querySelector('[data-authentication]')?.addEventListener('click', () => {
        state.authFailed = {...state.authFailed, [message.id]: false};
        loadAuthentication(message);
    });

    if (state.tab === 'report') {
        loadAuthentication(message);
    }

    el.detail.querySelectorAll('[data-pane]').forEach((button) => {
        button.addEventListener('click', () => {
            state.sourcePane = button.dataset.pane;
            renderDetail(message);
        });
    });

    el.detail.querySelectorAll('[data-source]').forEach((button) => {
        button.addEventListener('click', () => {
            state.sourceView = button.dataset.source;
            renderDetail(message);
        });
    });

    el.detail.querySelectorAll('[data-raw]').forEach((button) => {
        button.addEventListener('click', () => {
            state.rawView = button.dataset.raw;
            renderDetail(message);
        });
    });

    el.detail.querySelectorAll('[data-body]').forEach((button) => {
        button.addEventListener('click', () => {
            state.mailBody = button.dataset.body;
            renderDetail(message);
        });
    });

    el.detail.querySelectorAll('[data-dlr]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            await api(`/messages/${message.id}/dlr`, {
                method: 'POST',
                body: JSON.stringify({status: button.dataset.dlr}),
            });
            await openMessage(message.id);
        });
    });
};

/**
 * The tab bar scrolls sideways when its tabs do not fit. Two things have to happen after every
 * render, because renderDetail() rebuilds the bar and the browser forgets where it was: the
 * selected tab has to be brought back into view, and the fade has to match what is left to
 * scroll. scrollLeft is set directly rather than through scrollIntoView, which would also move
 * whatever is scrollable above it.
 */
const keepTabsUsable = () => {
    const bar = el.detail.querySelector('.tabs');

    if (!bar) {
        return;
    }

    const updateFade = () => {
        const room = bar.scrollWidth - bar.clientWidth;

        bar.classList.toggle('fade-left', bar.scrollLeft > 4);
        bar.classList.toggle('fade-right', room > 4 && bar.scrollLeft < room - 4);
    };

    const active = bar.querySelector('[aria-selected="true"]');

    if (active) {
        const left = active.offsetLeft - bar.offsetLeft;
        const right = left + active.offsetWidth;

        if (left < bar.scrollLeft) {
            bar.scrollLeft = Math.max(0, left - 20);
        } else if (right > bar.scrollLeft + bar.clientWidth) {
            bar.scrollLeft = right - bar.clientWidth + 20;
        }
    }

    bar.addEventListener('scroll', updateFade, {passive: true});
    updateFade();
};

const clearDetail = () => {
    state.selectedId = null;
    writeHash();
    el.detail.innerHTML = '<p class="empty">Select a message to inspect it.</p>';
};

/** Reflects the current selection in the url without adding a history entry per click. */
const writeHash = () => {
    if (state.doc !== null) {
        history.replaceState(null, '', `#docs/${state.doc}`);

        return;
    }

    history.replaceState(null, '', state.selectedId === null ? ' ' : `#m/${state.selectedId}/${state.tab}`);
};

const openMessage = async (id) => {
    state.selectedId = id;

    const message = await api(`/messages/${id}`);

    renderDetail(message);

    if (!message.read) {
        const {unread} = await api(`/messages/${id}/read`, {method: 'POST'});

        state.unread = unread;
        state.signature = '';
        await refresh();
    }

    el.messages.querySelectorAll('li').forEach((item) => {
        item.setAttribute('aria-selected', String(item.dataset.id === id));
    });

    writeHash();
};

const refresh = async () => {
    const {messages, unread} = await api('/messages');

    state.messages = messages;
    state.unread = unread;
    el.markRead.hidden = unread === 0;

    // Only rebuild the list when something changed, so polling does not fight with scrolling.
    const signature = messages.map((message) => `${message.id}:${message.status}:${message.read}`).join(',')
        + `|${state.filter.provider}|${state.filter.channel}|${state.search}`;

    if (signature !== state.signature) {
        state.signature = signature;
        renderList();
        renderSidebar();
    }

    renderStats();

    if (state.selectedId && !messages.some((message) => message.id === state.selectedId)) {
        clearDetail();
    }

    // Open the newest message on first load, so the pane is never pointlessly empty.
    if (state.selectedId === null && messages.length > 0 && !state.touched) {
        state.touched = true;
        await openMessage(messages[0].id);
    }
};

const loadProviders = async () => {
    const {providers, smtp, version} = await api('/providers');

    state.endpoints = {providers, smtp};
    state.dlrProviders = providers.filter((provider) => provider.deliveryReports).map((provider) => provider.id);

    // Only a real release gets the v prefix; "dev" and "dev-<sha>" stand on their own.
    el.version.textContent = /^\d/.test(version) ? `v${version}` : version;

    // Providers with no messages yet should still be visible in the sidebar.
    if (providers.length > 0 && state.messages.length === 0) {
        el.navProviders.innerHTML = providers.map((provider) => `
            <li><button type="button" data-filter="provider" data-value="${escapeHtml(provider.id)}"
                        aria-current="false"><span>${escapeHtml(provider.id)}</span>
                <span class="count">0</span></button></li>
        `).join('');
    }
};


/** The scenario table is rendered from the API, so the docs cannot drift from the code. */
const scenarioTable = () => {
    if (state.scenarios.length === 0) {
        return '';
    }

    const rows = state.scenarios
        .filter((scenario) => scenario.recipient)
        .map((scenario) => `
            <tr>
                <td><code>${escapeHtml(scenario.recipient)}</code></td>
                <td>${escapeHtml(scenario.scenario)}</td>
                <td>${escapeHtml(scenario.description)}</td>
            </tr>
        `).join('');

    return `<div class="table-scroll"><table>
        <thead><tr><th>Recipient</th><th>Scenario</th><th>What your app sees</th></tr></thead>
        <tbody>${rows}</tbody>
    </table></div>`;
};

const closeDocsMenu = () => {
    el.navDocs.hidden = true;
    el.docsToggle.setAttribute('aria-expanded', 'false');
};

const toggleDocsMenu = () => {
    const opening = el.navDocs.hidden;

    el.navDocs.hidden = !opening;
    el.docsToggle.setAttribute('aria-expanded', String(opening));
};

const openDoc = async (slug) => {
    const {markdown} = await api(`/docs/${slug}`);

    state.doc = slug;
    closeDocsMenu();
    el.workspace.classList.add('reading');
    el.docs.hidden = false;
    el.docs.innerHTML = renderMarkdown(markdown, {scenarios: scenarioTable()});
    el.docs.scrollTop = 0;

    el.navDocs.querySelectorAll('button').forEach((button) => {
        button.setAttribute('aria-current', String(button.dataset.doc === slug));
    });

    writeHash();
};

const closeDocs = () => {
    state.doc = null;
    el.workspace.classList.remove('reading');
    el.docs.hidden = true;
    el.navDocs.querySelectorAll('button').forEach((button) => button.setAttribute('aria-current', 'false'));
    writeHash();
};

const loadDocs = async () => {
    const [{pages}, {scenarios}] = await Promise.all([api('/docs'), api('/scenarios')]);

    state.scenarios = scenarios;
    el.navDocs.innerHTML = pages.map((page) => `
        <li role="none">
            <button type="button" role="menuitem" data-doc="${escapeHtml(page.slug)}" aria-current="false">
                ${escapeHtml(page.title)}
            </button>
        </li>
    `).join('');
};


/**
 * Desktop notifications for captured messages.
 *
 * The Notifications API needs a secure context, and Docksal serves projects over plain http by
 * default, so the button explains that rather than silently doing nothing. Over https it works,
 * including with Docksal's self-signed certificate once you accept it.
 */
const notifications = {
    key: 'msgpit.notifications',
    queue: [],
    timer: null,

    get available() {
        return 'Notification' in window && window.isSecureContext;
    },

    get permission() {
        return this.available ? Notification.permission : 'unsupported';
    },

    get wanted() {
        try {
            return localStorage.getItem(this.key) === 'on';
        } catch {
            return false;
        }
    },

    set wanted(value) {
        try {
            localStorage.setItem(this.key, value ? 'on' : 'off');
        } catch {
            // A private window refuses storage; the choice then lasts for this page only.
        }
    },

    get active() {
        return this.available && this.permission === 'granted' && this.wanted;
    },

    render() {
        const button = el.notify;

        button.setAttribute('aria-pressed', String(this.active));

        if (!this.available) {
            button.dataset.state = 'unavailable';
            button.title = 'Desktop notifications need https. Click to reopen this page securely.';

            return;
        }

        if (this.permission === 'denied') {
            button.dataset.state = 'unavailable';
            button.title = 'Your browser is blocking notifications for this site.';

            return;
        }

        button.dataset.state = this.active ? 'on' : 'off';
        button.title = this.active ? 'Desktop notifications are on' : 'Turn on desktop notifications';
    },

    async toggle() {
        if (!this.available) {
            // http cannot ask for permission at all, so send the user somewhere that can.
            if (location.protocol === 'http:') {
                location.href = `https://${location.host}${location.pathname}${location.hash}`;
            }

            return;
        }

        if (this.permission === 'denied') {
            return;
        }

        if (this.permission === 'default') {
            this.wanted = await Notification.requestPermission() === 'granted';
            this.render();

            return;
        }

        this.wanted = !this.wanted;
        this.render();
    },

    /** Collected briefly, so one request to fifty recipients is one notification and not fifty. */
    queueMessage(message) {
        if (!this.active || !document.hidden) {
            return;
        }

        this.queue.push(message);
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.flush(), 400);
    },

    flush() {
        const queued = this.queue.splice(0);

        if (queued.length === 0) {
            return;
        }

        const single = queued.length === 1 ? queued[0] : null;
        const notification = new Notification(
            single ? `${single.provider} to ${single.to}` : `${queued.length} new messages`,
            {
                body: single ? single.body : queued.map((message) => message.to).join(', '),
                tag: 'msgpit',
                icon: document.querySelector('link[rel="icon"]')?.href,
            },
        );

        notification.onclick = () => {
            window.focus();
            notification.close();

            if (single) {
                state.touched = true;
                closeDocs();
                openMessage(single.id);
            }
        };
    },
};

const setConnection = (label, className) => {
    el.connection.textContent = label;
    el.connection.className = `connection ${className}`;
};

document.querySelector('.sidebar').addEventListener('click', (event) => {
    const button = event.target.closest('[data-filter]');

    if (!button) {
        return;
    }

    closeDocs();

    if (button.dataset.filter === 'all') {
        state.filter = {provider: '', channel: ''};
    } else {
        const type = button.dataset.filter;
        state.filter = {...state.filter, [type]: state.filter[type] === button.dataset.value ? '' : button.dataset.value};
    }

    state.signature = '';
    refresh();
});

// The logo is the way back: reference closed, filters dropped, the whole inbox again.
el.brand.addEventListener('click', (event) => {
    event.preventDefault();
    closeDocs();
    el.search.value = '';
    state.search = '';
    state.filter = {provider: '', channel: ''};
    state.signature = '';
    refresh();
});

el.docsToggle.addEventListener('click', () => toggleDocsMenu());

el.navDocs.addEventListener('click', (event) => {
    const button = event.target.closest('[data-doc]');

    if (button) {
        openDoc(button.dataset.doc);
    }
});

// Anywhere outside the menu closes it, Escape included.
document.addEventListener('click', (event) => {
    if (!event.target.closest('.menu')) {
        closeDocsMenu();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    if (!el.navDocs.hidden) {
        closeDocsMenu();
        el.docsToggle.focus();
    }

    if (el.dropzone.classList.contains('failed')) {
        showDropzone(false);
    }
});

el.messages.addEventListener('click', (event) => {
    const item = event.target.closest('li');

    if (item) {
        state.touched = true;
        closeDocs();
        openMessage(item.dataset.id);
    }
});

el.docs.addEventListener('click', (event) => {
    const link = event.target.closest('a[href^="#docs/"]');

    if (link) {
        event.preventDefault();
        openDoc(link.getAttribute('href').slice('#docs/'.length));
    }
});

el.search.addEventListener('input', () => {
    state.search = el.search.value.trim();
    state.signature = '';
    refresh();
});

el.scenario.addEventListener('change', () => api('/scenario', {
    method: 'POST',
    body: JSON.stringify({scenario: el.scenario.value || null}),
}));

el.notify.addEventListener('click', () => notifications.toggle());

el.markRead.addEventListener('click', async () => {
    await api('/messages/read', {method: 'POST'});
    state.signature = '';
    await refresh();
});

// The reliable way in: a drag out of a mail client does not always carry the file itself.
el.import.addEventListener('click', () => el.importInput.click());

el.importInput.addEventListener('change', async () => {
    const files = Array.from(el.importInput.files ?? []);

    // Reset first, or picking the same file twice fires no second change event.
    el.importInput.value = '';

    if (files.length > 0) {
        await importFiles(files);
    }
});

el.clear.addEventListener('click', async () => {
    await api('/messages', {method: 'DELETE'});
    clearDetail();
    state.signature = '';
    await refresh();
});

/**
 * Dropping a .eml from a mail client. The file goes up as it came off disk, because re-encoding it
 * would change the very thing the spam and html checks are asked to judge.
 *
 * Counting enter and leave rather than toggling on each: dragging over a child element fires a
 * leave for the parent, and the overlay would flicker away under the cursor.
 */
let dragDepth = 0;
let dragTimer = null;

const dragTypes = (event) => Array.from(event.dataTransfer?.types ?? []);

/**
 * Whether a drag is worth showing the overlay for. Not only "Files": a message dragged out of
 * macOS Mail announces itself as a url or as nothing at all, because the file does not exist yet
 * when the drag starts. The browser is willing to open it, so it is a file in every way that
 * matters here.
 */
const carriesFiles = (event) => {
    const types = dragTypes(event);

    return types.includes('Files') || types.includes('text/uri-list') || types.length === 0;
};

/**
 * The files in a drop, from whichever place the browser put them. `files` is empty for a drag that
 * was a promise until the moment it landed; `items` still has it then.
 */
const droppedFiles = (transfer) => {
    const files = Array.from(transfer?.files ?? []);

    if (files.length > 0) {
        return files;
    }

    return Array.from(transfer?.items ?? [])
        .filter((item) => item.kind === 'file')
        .map((item) => item.getAsFile())
        .filter((file) => file !== null);
};

const showDropzone = (visible, note = null) => {
    el.dropzone.hidden = !visible;
    el.dropzoneNote.textContent = note ?? 'They are imported and checked, not delivered';
    el.dropzone.classList.toggle('failed', note !== null);
};

const importFiles = async (files) => {
    const results = await Promise.all(files.map(async (file) => {
        try {
            const response = await fetch('/api/messages/import', {
                method: 'POST',
                headers: {'Content-Type': 'message/rfc822', 'X-Msgpit-Filename': encodeURIComponent(file.name)},
                body: await file.arrayBuffer(),
            });

            return response.ok ? null : (await response.json().catch(() => ({}))).error ?? `Import failed: ${response.status}`;
        } catch {
            return 'Import failed: msgpit did not answer';
        }
    }));

    const failed = results.filter((error) => error !== null);

    if (failed.length > 0) {
        showDropzone(true, failed[0]);

        return;
    }

    state.signature = '';
    await refresh();
};

const closeDropzone = () => {
    clearTimeout(dragTimer);
    dragTimer = null;
    dragDepth = 0;
    showDropzone(false);
};

/**
 * A drag released outside the window, or onto another application, fires neither a leave nor a
 * drop, and the overlay would then cover the whole UI until the page is reloaded. Drag events keep
 * arriving as long as the pointer is over the window, so their silence is what ends the drag.
 */
const keepDropzoneAlive = () => {
    clearTimeout(dragTimer);
    dragTimer = setTimeout(closeDropzone, DRAG_IDLE_MS);
};

// A failure tells you what to do next, so it waits to be read rather than timing out.
el.dropzone.addEventListener('click', () => {
    if (el.dropzone.classList.contains('failed')) {
        showDropzone(false);
    }
});

/**
 * Why a drop carried no file.
 *
 * macOS Mail puts a "message:" url on the drag: a pointer to the message inside Mail, by its
 * Message-ID. No bytes travel with it and only Mail can resolve it, so there is nothing here to
 * import however carefully the drop is read. Do not try again, and say so rather than failing
 * generically.
 *
 * Read here and now: outside the drop event the data is walled off and every read comes back
 * empty, which looks exactly like a drag that carried nothing.
 */
const dropFailure = (transfer) => {
    const uri = Array.from(transfer?.types ?? []).includes('text/uri-list')
        ? decodeURIComponent(String(transfer.getData('text/uri-list') ?? ''))
        : '';

    return uri.startsWith('message:')
        ? 'macOS Mail handed over a link to the message, not the message itself.\nDrag it to the Finder first, then drop the .eml here, or use Import .eml.'
        : 'Your mail client handed over no file.\nSave the message first, or use Import .eml.';
};

window.addEventListener('dragenter', (event) => {
    if (!carriesFiles(event)) {
        return;
    }

    dragDepth++;
    showDropzone(true);
    keepDropzoneAlive();
});

// Leaving through the window edge has no relatedTarget, and no further event is coming.
window.addEventListener('dragleave', (event) => {
    dragDepth = event.relatedTarget === null ? 0 : Math.max(0, dragDepth - 1);

    if (dragDepth === 0) {
        closeDropzone();
    }
});

/** Dropping text into the filter box is the browser's job, not ours. */
const dropsIntoAField = (event) => !carriesFiles(event)
    && event.target instanceof Element
    && event.target.closest('input, textarea, select') !== null;

/*
 * Unconditionally for everything else, and this is the whole trick: without it the browser
 * opens what you dropped and the page is gone. Testing the types first is too clever, because a drag out of a mail client
 * does not always say it carries a file, and by the time we know it is too late to object.
 */
window.addEventListener('dragover', (event) => {
    if (dropsIntoAField(event)) {
        return;
    }

    event.preventDefault();
    keepDropzoneAlive();
});

window.addEventListener('drop', async (event) => {
    if (dropsIntoAField(event)) {
        return;
    }

    event.preventDefault();
    closeDropzone();

    const files = droppedFiles(event.dataTransfer);

    if (files.length > 0) {
        await importFiles(files);

        return;
    }

    showDropzone(true, dropFailure(event.dataTransfer));
});

// SSE is the live path; polling only takes over while the stream is down.
let fallbackTimer = null;
let lastSeq = 0;

const connect = () => {
    const stream = new EventSource(`/api/stream?seq=${lastSeq}`);

    stream.onopen = () => {
        clearInterval(fallbackTimer);
        fallbackTimer = null;
        setConnection('Live', 'live');
    };

    stream.onmessage = (event) => {
        const payload = JSON.parse(event.data);
        lastSeq = payload.seq;
        state.signature = '';
        refresh();

        if (payload.type === 'message' && payload.message) {
            notifications.queueMessage(payload.message);
        }

        // A status change on the open message should update the pane, not just the list.
        if ((payload.type === 'status' || payload.type === 'read') && payload.message?.id === state.selectedId) {
            openMessage(state.selectedId);
        }
    };

    // EventSource reconnects by itself; polling bridges the gap.
    stream.onerror = () => {
        setConnection('Polling', 'polling');
        fallbackTimer ??= setInterval(refresh, FALLBACK_POLL_MS);
    };
};

await loadProviders();
await refresh();
await loadDocs();
notifications.render();

// Land back on whatever the url pointed at: a reference page, or a message and its tab.
if (opened.doc !== null) {
    await openDoc(opened.doc);
} else if (opened.message !== null) {
    if (state.messages.some((message) => message.id === opened.message)) {
        await openMessage(opened.message);
    } else {
        // Cleared or pruned. Say so rather than quietly showing a different message, and keep
        // "touched" set so the next refresh does not open one either.
        el.detail.innerHTML = '<p class="empty">That message is no longer here.<br>Pick another from the list.</p>';
        state.selectedId = null;
    }
}

// Opened after load: a stream started during page load keeps the tab spinner running forever.
if (document.readyState === 'complete') {
    connect();
} else {
    window.addEventListener('load', () => setTimeout(connect, 0), {once: true});
}
