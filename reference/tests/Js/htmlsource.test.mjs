/**
 * Tests for the html source view. Two jobs: lay the source out over lines, since mail html
 * arrives as one, and colour it without ever letting the message's own markup through.
 *
 * Run with: fin exec node tests/Js/htmlsource.test.mjs
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {renderHtmlSource, tokenize} from '../../public/ui/htmlsource.js';

/** The visible text, with the colouring stripped back out. */
const plain = (html) => html
    .replace(/<span class="tok-[a-z]+">/g, '')
    .replace(/<\/span>/g, '')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');

test('tokenizing loses nothing', () => {
    const source = '<p class="a">tekst <b>vet</b> & meer</p><!-- x -->';

    assert.equal(tokenize(source).map((token) => token.raw).join(''), source);
});

test('block elements each get a line and a level', () => {
    const out = plain(renderHtmlSource('<html><body><p>hoi</p></body></html>'));

    assert.equal(out, [
        '<html>',
        '  <body>',
        '    <p>',
        '      hoi',
        '    </p>',
        '  </body>',
        '</html>',
    ].join('\n'));
});

/** Breaking inline elements onto their own lines changes how a sentence reads. */
test('inline elements stay in the text', () => {
    const out = plain(renderHtmlSource('<p>een <b>twee</b> drie</p>'));

    assert.match(out, /een <b>twee<\/b> drie/);
});

test('void elements do not open a level', () => {
    const out = plain(renderHtmlSource('<div><img src="x.png"><p>na</p></div>'));

    assert.match(out, /^ {2}<p>$/m, 'the paragraph sits one level in, not two');
});

test('tags, attributes and values are coloured apart', () => {
    const out = renderHtmlSource('<p style="color:red">x</p>');

    assert.match(out, /<span class="tok-tag">p<\/span>/);
    assert.match(out, /<span class="tok-attr">style<\/span>/);
    assert.match(out, /<span class="tok-value">&quot;color:red&quot;<\/span>/);
});

test('an attribute without a value is still an attribute', () => {
    const out = renderHtmlSource('<input disabled>');

    assert.match(out, /<span class="tok-attr">disabled<\/span>/);
});

test('comments and doctypes are marked', () => {
    assert.match(renderHtmlSource('<!-- hoi -->'), /<span class="tok-comment">/);
    assert.match(renderHtmlSource('<!DOCTYPE html>'), /<span class="tok-doctype">/);
});

/**
 * The colouring inserts spans with class attributes of its own. Replacing in the escaped string
 * made the attribute pass match those, which turned every tag into "&lt;class="tok-tag"&gt;".
 */
test('the colouring does not colour itself', () => {
    const out = renderHtmlSource('<p class="original">x</p>');

    assert.doesNotMatch(out, /tok-attr">class<\/span>="tok-tag/);
    assert.match(plain(out), /<p class="original">/);
});

test('markup in the message never escapes into the page', () => {
    for (const source of ['<script>alert(1)</script>', '<p>een & twee</p>', '<p title="</p>">x</p>']) {
        const out = renderHtmlSource(source);

        assert.doesNotMatch(out, /<script/, source);
        // The only tags in the output are our own spans.
        assert.equal(out.replace(/<\/?span[^>]*>/g, '').includes('<'), false, source);
    }
});

test('text is escaped in the formatted view as well', () => {
    assert.match(renderHtmlSource('<p>een & twee</p>'), /een &amp; twee/);
    assert.match(renderHtmlSource('<p>een & twee</p>', {format: false}), /een &amp; twee/);
});

test('the original view keeps it on one line', () => {
    const source = '<html><body><p>hoi</p></body></html>';
    const out = renderHtmlSource(source, {format: false});

    assert.equal(out.includes('\n'), false);
    assert.equal(plain(out), source);
});

test('empty input does not explode', () => {
    assert.equal(renderHtmlSource(''), '');
    assert.equal(renderHtmlSource(null), '');
});

test('a stray closing tag does not push the indent below zero', () => {
    const out = plain(renderHtmlSource('</div><p>hoi</p>'));

    assert.doesNotMatch(out, /^\s{4,}<p>/m, 'the paragraph should not be pushed out by a tag that closed nothing');
});
