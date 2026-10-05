/**
 * Tests for the raw message view. The thing it has to get right is not colour but structure:
 * telling headers from bodies, marking boundaries, and folding away the base64 that makes a
 * message with an attachment unreadable.
 *
 * Run with: fin exec node tests/Js/rawmessage.test.mjs
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {renderRawMessage} from '../../public/ui/rawmessage.js';

const message = (body) => [
    'SMTP inbound HTTP/1.1',
    '',
    'From: InvalPool <info@invalpool.nl>',
    'To: raymond@example.test',
    'Subject: Aanstelling',
    'Content-Type: multipart/mixed; boundary="mix"',
    '',
    body,
].join('\n');

test('headers are marked as headers', () => {
    const html = renderRawMessage(message('tekst'));

    assert.match(html, /<span class="raw-name">From:<\/span>/);
    assert.match(html, /<span class="raw-name">Subject:<\/span>/);
});

/**
 * The envelope line sits above the message with a blank line after it. Treating that blank line
 * as the end of the headers would leave From and Subject looking like body text.
 */
test('the envelope line does not end the header block', () => {
    const html = renderRawMessage(message('tekst'));

    assert.match(html, /<span class="raw-name">To:<\/span>/, 'To comes after the envelope line');
});

test('boundaries are set apart', () => {
    const html = renderRawMessage(message('--mix\nContent-Type: text/plain\n\nhoi\n--mix--'));

    assert.match(html, /<div class="raw-boundary">--mix<\/div>/);
    assert.match(html, /<div class="raw-boundary">--mix--<\/div>/);
});

test('a boundary starts a new header block', () => {
    const html = renderRawMessage(message('--mix\nContent-Type: text/plain\n\nhoi'));

    assert.match(html, /<span class="raw-name">Content-Type:<\/span>/);
});

test('body text is not mistaken for headers', () => {
    const html = renderRawMessage(message('--mix\nContent-Type: text/plain\n\nBeste Jansen: tot ziens\n--mix--'));

    assert.doesNotMatch(html, /<span class="raw-name">Beste Jansen:<\/span>/, 'A colon in a sentence is not a header');
});

test('base64 is folded away with its size', () => {
    const blob = Array.from({length: 40}, () => 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVphYmNkZWZnaGlqa2xtbm9w').join('\n');
    const html = renderRawMessage(message(`--mix\nContent-Transfer-Encoding: base64\n\n${blob}\n--mix--`));

    assert.match(html, /<details class="blob">/);
    assert.match(html, /kB base64<\/span>/, 'The size is what you want to know at a glance');
    assert.match(html, /class="blob-preview"/);
});

test('the folded content is still there, just closed', () => {
    const line = 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVphYmNkZWZnaGlqa2xtbm9w';
    const html = renderRawMessage(message(`--mix\n\n${line}\n${line}\n--mix--`));

    assert.match(html, new RegExp(line), 'Nothing is thrown away');
    assert.match(html, /<details/, 'but it starts collapsed');
});

test('one short base64-looking word is not a blob', () => {
    const html = renderRawMessage(message('Dit is gewone tekst met korte woorden.'));

    assert.doesNotMatch(html, /<details class="blob">/);
});

test('html is escaped', () => {
    const html = renderRawMessage(message('<script>alert(1)</script>'));

    assert.doesNotMatch(html, /<script>/);
    assert.match(html, /&lt;script&gt;/);
});

test('an empty message does not explode', () => {
    assert.match(renderRawMessage(''), /<div class="raw-message">/);
});

test('crlf line endings are handled', () => {
    const crlf = message('--mix\nContent-Type: text/plain\n\nhoi').replace(/\n/g, '\r\n');
    const html = renderRawMessage(crlf);

    assert.match(html, /<div class="raw-boundary">--mix<\/div>/, 'A stray carriage return must not end up in the output');
    assert.match(html, /<span class="raw-name">From:<\/span>/);
});

test('the headers of a block share one grid, so their values line up', () => {
    const html = renderRawMessage(message('tekst'));
    const blocks = html.match(/<div class="raw-headers">.*?<\/div>/gs) ?? [];

    assert.equal(blocks.length, 1, 'The four headers at the top form a single block');
    assert.match(blocks[0], /<span class="raw-name">From:<\/span><span class="raw-value">InvalPool &lt;info@invalpool\.nl&gt;<\/span>/);
    assert.match(blocks[0], /<span class="raw-name">Subject:<\/span>/);
});

/**
 * Aligning over the whole message would let one long Content-Disposition in an attachment push
 * From and To far to the right. Every block gets its own column instead.
 */
test('each header block is aligned on its own', () => {
    const html = renderRawMessage(message([
        '--mix',
        'Content-Type: application/pdf; name="a.pdf"',
        'Content-Disposition: attachment; filename="a.pdf"',
        '',
        'hoi',
        '--mix--',
    ].join('\n')));
    const blocks = html.match(/<div class="raw-headers">.*?<\/div>/gs) ?? [];

    assert.equal(blocks.length, 2, 'The main headers and the part headers are separate blocks');
    assert.doesNotMatch(blocks[0], /Content-Disposition/, 'The long part header stays out of the top block');
    assert.match(blocks[1], /<span class="raw-name">Content-Disposition:<\/span>/);
});

test('a folded header runs along with the value column', () => {
    const html = renderRawMessage(message('tekst').replace(
        'Subject: Aanstelling',
        'Subject: Aanstelling\n bevestigd',
    ));
    const blocks = html.match(/<div class="raw-headers">.*?<\/div>/gs) ?? [];

    assert.equal(blocks.length, 1, 'A continuation line does not break the block');
    assert.match(blocks[0], /<span class="raw-value raw-folded">bevestigd<\/span>/);
});

/**
 * The b= of a DKIM signature is a continuation line that looks exactly like base64. Folding it
 * away would tear the header block in two and leave the headers below it aligned separately.
 */
test('a base64-looking continuation line stays in its header block', () => {
    const signature = 'Xy8Kq2mZ0LpQwErTyUiOpAsDfGhJkLzXcVbNmQwErTyUiOpAsDfGhJkLzXcVbNm';
    const html = renderRawMessage(message('tekst').replace(
        'To: raymond@example.test',
        `DKIM-Signature: v=1; a=rsa-sha256; d=invalpool.nl;\n b=${signature}\nTo: raymond@example.test`,
    ));
    const blocks = html.match(/<div class="raw-headers">.*?<\/div>/gs) ?? [];

    assert.doesNotMatch(html, /<details class="blob">/, 'The signature is part of the header, not a blob');
    assert.equal(blocks.length, 1, 'The block is not torn in two');
});

test('the envelope line gets no column', () => {
    const html = renderRawMessage(message('tekst'));

    assert.match(html, /<div class="raw-line">SMTP inbound HTTP\/1\.1<\/div>/, 'It is not a header and not a folded line');
    assert.doesNotMatch(html, /raw-folded">SMTP/);
});

test('every line of a real message ends up somewhere', () => {
    const html = renderRawMessage(message('--mix\nContent-Type: text/plain\n\nregel een\nregel twee\n--mix--'));

    for (const needle of ['regel een', 'regel twee', 'Content-Type', 'From']) {
        assert.match(html, new RegExp(needle), `${needle} is missing from the output`);
    }
});
