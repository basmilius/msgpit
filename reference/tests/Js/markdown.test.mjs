/**
 * Tests for the hand-written Markdown renderer. It only has to handle the syntax our own docs
 * use, so this pins that syntax down and then checks every real page renders without leftovers.
 *
 * Run with: fin exec node tests/Js/markdown.test.mjs
 */

import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync, readdirSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

import {renderMarkdown} from '../../public/ui/markdown.js';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

test('headings', () => {
    assert.equal(renderMarkdown('# Title'), '<h1>Title</h1>');
    assert.equal(renderMarkdown('### Deeper'), '<h3>Deeper</h3>');
    assert.equal(renderMarkdown('#NotAHeading'), '<p>#NotAHeading</p>');
});

test('a paragraph joins its lines and survives inline emphasis at the start of one', () => {
    const html = renderMarkdown('One line\n*emphasised* continues\nand ends.');

    assert.equal(html, '<p>One line <em>emphasised</em> continues and ends.</p>');
});

test('a blank line separates paragraphs', () => {
    assert.equal(renderMarkdown('First.\n\nSecond.'), '<p>First.</p>\n<p>Second.</p>');
});

test('bold, italic and inline code', () => {
    assert.equal(renderMarkdown('**strong**'), '<p><strong>strong</strong></p>');
    assert.equal(renderMarkdown('*emphasis*'), '<p><em>emphasis</em></p>');
    assert.equal(renderMarkdown('use `npm ci` here'), '<p>use <code>npm ci</code> here</p>');
});

test('markup inside inline code stays literal', () => {
    assert.equal(renderMarkdown('`**not bold**`'), '<p><code>**not bold**</code></p>');
});

test('links, with external ones opening in a new tab', () => {
    assert.equal(renderMarkdown('[docs](#docs/04-segments)'), '<p><a href="#docs/04-segments">docs</a></p>');
    assert.match(renderMarkdown('[site](https://example.com)'), /target="_blank" rel="noreferrer noopener"/);
});

test('unordered lists', () => {
    assert.equal(renderMarkdown('- one\n- two'), '<ul><li>one</li><li>two</li></ul>');
});

test('tables', () => {
    const html = renderMarkdown('| A | B |\n|---|---|\n| 1 | 2 |');

    assert.match(html, /<th>A<\/th><th>B<\/th>/);
    assert.match(html, /<td>1<\/td><td>2<\/td>/);
});

test('a pipe outside a table is not a table', () => {
    assert.equal(renderMarkdown('| not a table'), '<p>| not a table</p>');
});

test('fenced code blocks keep their content verbatim', () => {
    const html = renderMarkdown('```bash\ncurl -X POST url\n```');

    assert.equal(html, '<pre data-language="bash">curl -X POST url</pre>');
});

test('html in the source is escaped', () => {
    assert.equal(renderMarkdown('<script>alert(1)</script>'), '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
});

test('placeholders are filled by the application', () => {
    assert.equal(renderMarkdown('<!-- scenarios -->', {scenarios: '<table></table>'}), '<table></table>');
    assert.equal(renderMarkdown('<!-- unknown -->'), '');
});

test('every reference page renders without leftover markdown', () => {
    const dir = join(root, 'docs');
    const pages = readdirSync(dir).filter((file) => file.endsWith('.md'));

    assert.ok(pages.length > 0, 'there should be reference pages to check');

    for (const page of pages) {
        const html = renderMarkdown(readFileSync(join(dir, page), 'utf8'), {scenarios: '<table></table>'});

        assert.doesNotMatch(html, /\*\*/, `${page}: unparsed bold`);
        assert.doesNotMatch(html, /^\s*\|/m, `${page}: unparsed table row`);
        assert.doesNotMatch(html, /^\s*#{1,4}\s/m, `${page}: unparsed heading`);
        assert.doesNotMatch(html, /`/, `${page}: unparsed inline code`);
        assert.doesNotMatch(html, /\]\(/, `${page}: unparsed link`);
        assert.doesNotMatch(html, /<p>\s*<\/p>/, `${page}: empty paragraph`);
        assert.match(html, /<h1>/, `${page}: no title`);
    }
});
