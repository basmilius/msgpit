/**
 * Formats and colours the html of a captured message.
 *
 * One language, one job, so this is a tokenizer rather than a syntax highlighting library. Shiki
 * would do this well, but it brings a bundler, a WASM regex engine and a grammar bundle, and this
 * project has no build step and has to work offline.
 *
 * The colouring is the smaller half. Mail html arrives as a single line, so what actually makes it
 * readable is the indentation.
 */

/** Elements that own a line. Everything else flows with the text around it. */
const BLOCK = new Set([
    'html', 'head', 'body', 'div', 'p', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
    'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'footer',
    'section', 'article', 'main', 'aside', 'nav', 'form', 'fieldset', 'blockquote', 'pre',
    'style', 'script', 'center', 'title', 'meta', 'link', 'hr', 'figure', 'figcaption',
]);

/** Elements that never have a closing tag, so they must not open a level. */
const VOID = new Set([
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param',
    'source', 'track', 'wbr',
]);

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => (
    {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]
));

/**
 * Splits the source into tags, comments, doctypes and the text between them. Nothing is thrown
 * away, so joining the tokens back together returns the original.
 *
 * @return {{kind: string, raw: string, name?: string, closing?: boolean, selfClosing?: boolean}[]}
 */
export const tokenize = (source) => {
    const tokens = [];
    const pattern = /<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<![^>]*>|<\/?[a-zA-Z][^>]*>/g;
    let last = 0;
    let match;

    while ((match = pattern.exec(source)) !== null) {
        if (match.index > last) {
            tokens.push({kind: 'text', raw: source.slice(last, match.index)});
        }

        const raw = match[0];

        if (raw.startsWith('<!--')) {
            tokens.push({kind: 'comment', raw});
        } else if (raw.startsWith('<!')) {
            tokens.push({kind: 'doctype', raw});
        } else {
            const name = (raw.match(/^<\/?\s*([a-zA-Z][a-zA-Z0-9-]*)/) ?? [])[1]?.toLowerCase() ?? '';

            tokens.push({
                kind: 'tag',
                raw,
                name,
                closing: raw.startsWith('</'),
                selfClosing: raw.endsWith('/>') || VOID.has(name),
            });
        }

        last = pattern.lastIndex;
    }

    if (last < source.length) {
        tokens.push({kind: 'text', raw: source.slice(last)});
    }

    return tokens;
};

/**
 * Colours one tag: the name, its attribute names, and their values.
 *
 * Built up from the parts rather than by replacing in the escaped string. Chaining replaces was
 * the obvious way and the wrong one: the second pass matched the class attribute of the span the
 * first pass had just inserted.
 */
const colourTag = (raw) => {
    const match = raw.match(/^(<\/?)([a-zA-Z][a-zA-Z0-9-]*)([\s\S]*?)(\/?>)$/);

    if (match === null) {
        return escapeHtml(raw);
    }

    const [, open, name, attributes, close] = match;

    // Walk the attribute string once, escaping whatever sits between the attributes. Doing it in
    // two passes meant the second pass matched the spans the first had just inserted.
    const pattern = /([a-zA-Z_:][a-zA-Z0-9_.:-]*)(\s*=\s*)?("[^"]*"|'[^']*'|[^\s>]+)?/g;
    let coloured = '';
    let last = 0;
    let attribute;

    while ((attribute = pattern.exec(attributes)) !== null) {
        if (attribute[0] === '') {
            pattern.lastIndex++;

            continue;
        }

        coloured += escapeHtml(attributes.slice(last, attribute.index));
        coloured += `<span class="tok-attr">${escapeHtml(attribute[1])}</span>`;

        if (attribute[2] !== undefined) {
            coloured += escapeHtml(attribute[2]);
        }

        if (attribute[3] !== undefined) {
            coloured += `<span class="tok-value">${escapeHtml(attribute[3])}</span>`;
        }

        last = pattern.lastIndex;
    }

    coloured += escapeHtml(attributes.slice(last));

    return `${escapeHtml(open)}<span class="tok-tag">${escapeHtml(name)}</span>${coloured}${escapeHtml(close)}`;
};

const colour = (token) => {
    if (token.kind === 'comment') {
        return `<span class="tok-comment">${escapeHtml(token.raw)}</span>`;
    }

    if (token.kind === 'doctype') {
        return `<span class="tok-doctype">${escapeHtml(token.raw)}</span>`;
    }

    return token.kind === 'tag' ? colourTag(token.raw) : escapeHtml(token.raw);
};

/**
 * Lays the source out over lines. Block elements get their own line and open a level; inline
 * elements and text stay where they are, because breaking those apart changes how a mail reads
 * rather than how it looks.
 */
export const renderHtmlSource = (source, {format = true} = {}) => {
    const tokens = tokenize(source ?? '');

    if (!format) {
        return tokens.map(colour).join('');
    }

    const lines = [];
    let current = '';
    let depth = 0;

    const flush = () => {
        if (current.trim() !== '') {
            lines.push('  '.repeat(Math.max(0, depth)) + current.trim());
        }

        current = '';
    };

    for (const token of tokens) {
        const isBlock = token.kind === 'tag' && BLOCK.has(token.name ?? '');

        if (token.kind === 'text') {
            // Whitespace between block tags is layout, not content. Escaped like everything
            // else: this is the source of a captured message, not markup we trust.
            current += escapeHtml(token.raw.replace(/\s+/g, ' '));

            continue;
        }

        if (!isBlock && token.kind !== 'comment' && token.kind !== 'doctype') {
            current += colour(token);

            continue;
        }

        if (token.closing) {
            flush();
            depth--;
            lines.push('  '.repeat(Math.max(0, depth)) + colour(token));

            continue;
        }

        flush();
        lines.push('  '.repeat(Math.max(0, depth)) + colour(token));

        if (token.kind === 'tag' && !token.selfClosing) {
            depth++;
        }
    }

    flush();

    return lines.join('\n');
};
