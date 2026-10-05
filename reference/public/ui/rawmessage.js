/**
 * Lays out a raw email the way it is actually built: headers, boundaries, and the bodies in
 * between.
 *
 * A general syntax highlighter cannot help here, because the mess is not syntax. A message with
 * an attachment contains a single base64 line of fifteen thousand characters, and no colouring
 * makes that readable. Folding it away does.
 */

const BASE64_LINE = /^[A-Za-z0-9+/=]{40,}$/;

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => (
    {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]
));

const formatBytes = (bytes) => (bytes < 1024
    ? `${bytes} B`
    : (bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} kB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`));

export const renderRawMessage = (raw) => {
    // Strip every carriage return, not only the pairs: a stray one ends up visible otherwise.
    const lines = raw.replace(/\r/g, '').split('\n');
    const out = [];

    let inHeaders = true;
    let sawHeader = false;
    let blob = [];
    let headers = [];

    /**
     * Headers are collected per block and wrapped in a grid, so every value in one block starts at
     * the same column. Aligning per block and not over the whole message keeps a long
     * Content-Disposition in an attachment from pushing From and To far to the right.
     */
    const flushHeaders = () => {
        if (headers.length === 0) {
            return;
        }

        out.push(`<div class="raw-headers">${headers.join('')}</div>`);

        headers = [];
    };

    const flushBlob = () => {
        if (blob.length === 0) {
            return;
        }

        const bytes = blob.join('').length;
        const preview = blob[0].slice(0, 48);

        out.push(`<details class="blob">
            <summary><span class="blob-preview">${escapeHtml(preview)}…</span>
            <span class="blob-size">${formatBytes(bytes)} base64</span></summary>
            <pre>${escapeHtml(blob.join('\n'))}</pre>
        </details>`);

        blob = [];
    };

    for (const line of lines) {
        // A boundary both separates parts and starts a new header block.
        if (/^--[^\s]+-*$/.test(line.trim()) && line.trim().length > 3) {
            flushHeaders();
            flushBlob();
            out.push(`<div class="raw-boundary">${escapeHtml(line)}</div>`);
            inHeaders = true;
            sawHeader = false;

            continue;
        }

        // A continuation line of a folded header: it belongs in the value column of its block, even
        // when it looks like base64 (the b= of a DKIM signature does).
        const folded = inHeaders && headers.length > 0 && /^[ \t]/.test(line) && line.trim() !== '';

        if (!folded && BASE64_LINE.test(line.trim())) {
            flushHeaders();
            blob.push(line.trim());

            continue;
        }

        flushBlob();

        if (line.trim() === '') {
            flushHeaders();
            inHeaders = inHeaders && !sawHeader;
            out.push('<div class="raw-blank"></div>');

            continue;
        }

        const header = inHeaders ? line.match(/^([A-Za-z][A-Za-z0-9-]*):[ \t]*(.*)$/) : null;

        if (header) {
            sawHeader = true;
            headers.push(`<span class="raw-name">${escapeHtml(header[1])}:</span><span class="raw-value">${escapeHtml(header[2])}</span>`);

            continue;
        }

        if (folded) {
            headers.push(`<span class="raw-value raw-folded">${escapeHtml(line.replace(/^[ \t]+/, ''))}</span>`);

            continue;
        }

        flushHeaders();
        out.push(`<div class="raw-line">${escapeHtml(line)}</div>`);
    }

    flushHeaders();
    flushBlob();

    return `<div class="raw-message">${out.join('')}</div>`;
};
