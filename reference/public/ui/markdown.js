/**
 * A small Markdown renderer for our own reference docs in docs/.
 *
 * It handles exactly the syntax those files use: headings, paragraphs, unordered lists, tables,
 * fenced code blocks, inline code, bold, italic and links. It is not CommonMark and is not meant
 * to be; anything it does not recognise is left as text.
 */

// Marks where inline code was lifted out; an escape sequence, so this file stays plain text.
const CODE_SENTINEL = '\u0000';

const escapeHtml = (value) => String(value ?? '').replace(/[&<>]/g, (char) => (
    {'&': '&amp;', '<': '&lt;', '>': '&gt;'}[char]
));

const inline = (text) => {
    // Inline code is pulled out first so bold and links inside it stay literal.
    const codes = [];
    const withPlaceholders = text.replace(/`([^`]+)`/g, (match, code) => {
        codes.push(code);

        return `${CODE_SENTINEL}${codes.length - 1}${CODE_SENTINEL}`;
    });

    const html = escapeHtml(withPlaceholders)
        .replace(/\[([^\]]+)]\(([^)]+)\)/g, (match, label, href) => {
            const attributes = /^https?:/.test(href) ? ' target="_blank" rel="noreferrer noopener"' : '';

            return `<a href="${escapeHtml(href)}"${attributes}>${label}</a>`;
        })
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');

    return html.replace(
        new RegExp(`${CODE_SENTINEL}(\\d+)${CODE_SENTINEL}`, 'g'),
        (match, index) => `<code>${escapeHtml(codes[Number(index)])}</code>`,
    );
};

/** A line that starts a new block, so a paragraph must stop before it. Italic is not a block. */
const isBlockStart = (line) => /^([-*]\s|#{1,4}\s|\||```|<!--)/.test(line);

const tableRow = (line) => line.slice(1, -1).split('|').map((cell) => cell.trim());

const isTableDivider = (line) => /^\|[\s:|-]+\|$/.test(line.trim());

export const renderMarkdown = (markdown, placeholders = {}) => {
    const lines = markdown.replace(/\r\n/g, '\n').split('\n');
    const html = [];
    let index = 0;

    while (index < lines.length) {
        const trimmed = lines[index].trim();

        if (trimmed === '') {
            index++;
            continue;
        }

        // Marks a spot the application fills in, such as the scenario table.
        const placeholder = trimmed.match(/^<!--\s*(\w+)\s*-->$/);

        if (placeholder) {
            html.push(placeholders[placeholder[1]] ?? '');
            index++;
            continue;
        }

        if (trimmed.startsWith('```')) {
            const language = trimmed.slice(3).trim();
            const body = [];
            index++;

            while (index < lines.length && !lines[index].trim().startsWith('```')) {
                body.push(lines[index]);
                index++;
            }

            index++;
            html.push(`<pre data-language="${escapeHtml(language)}">${escapeHtml(body.join('\n'))}</pre>`);
            continue;
        }

        const heading = trimmed.match(/^(#{1,4})\s+(.*)$/);

        if (heading) {
            const level = heading[1].length;
            html.push(`<h${level}>${inline(heading[2])}</h${level}>`);
            index++;
            continue;
        }

        if (trimmed.startsWith('|') && isTableDivider(lines[index + 1] ?? '')) {
            const head = tableRow(trimmed);
            const body = [];
            index += 2;

            while (index < lines.length && lines[index].trim().startsWith('|')) {
                body.push(tableRow(lines[index].trim()));
                index++;
            }

            const headCells = head.map((cell) => `<th>${inline(cell)}</th>`).join('');
            const bodyRows = body
                .map((row) => `<tr>${row.map((cell) => `<td>${inline(cell)}</td>`).join('')}</tr>`)
                .join('');

            html.push(
                '<div class="table-scroll"><table>'
                + `<thead><tr>${headCells}</tr></thead><tbody>${bodyRows}</tbody>`
                + '</table></div>',
            );
            continue;
        }

        if (/^[-*]\s+/.test(trimmed)) {
            const items = [];

            while (index < lines.length && /^[-*]\s+/.test(lines[index].trim())) {
                items.push(`<li>${inline(lines[index].trim().replace(/^[-*]\s+/, ''))}</li>`);
                index++;
            }

            html.push(`<ul>${items.join('')}</ul>`);
            continue;
        }

        // Anything else is a paragraph, running until the next blank line or block.
        const paragraph = [];

        while (index < lines.length && lines[index].trim() !== '' && !isBlockStart(lines[index].trim())) {
            paragraph.push(lines[index].trim());
            index++;
        }

        if (paragraph.length === 0) {
            // Looked like a block but did not parse as one; keep the line rather than loop on it.
            paragraph.push(trimmed);
            index++;
        }

        html.push(`<p>${inline(paragraph.join(' '))}</p>`);
    }

    return html.join('\n');
};
