# Email

msgpit catches SMTP as well, so everything an application sends ends up in one place instead of a
mail catcher beside a message catcher. It listens on port 1025 and stores what arrives, the same
way it stores an SMS: one message per recipient, with the raw form kept alongside.

This replaces Mailpit for our own projects. It is not a full mail client: no POP3, no link
checking, no Outlook compatibility report.

## Pointing your project at it

Docksal's `cli` image already sends mail to a host called `mail` on port 1025:

```
sendmail_path = '/usr/bin/msmtp -t --host=mail --port=1025 --from=docker@cli'
```

The msgpit container answers to the network aliases `mail` and `mailpit`, so replacing the mail
service in `.docksal/docksal.yml` is enough. Nothing in your application changes: PHP's `mail()`,
Symfony Mailer with `smtp://mail:1025`, and Laravel with `MAIL_HOST=mailpit` all arrive.

```yaml
services:
  msgpit:
    hostname: msgpit
    image: ${MSGPIT_IMAGE:-ghcr.io/axilium/msgpit:1}
    volumes:
      - msgpit_data:/data
    networks:
      default:
        aliases:
          - mail
          - mailpit
    labels:
      - io.docksal.virtual-host=msgpit.${VIRTUAL_HOST},msgpit.${VIRTUAL_HOST}.*
      - io.docksal.virtual-port=8080
```

There is no authentication and no TLS, on purpose. A client only uses what the server offers, and
this only ever listens inside a development network. If a client is configured with a username and
password, it simply will not use them.

## What you see

**Preview** renders the html exactly as the recipient would get it, in a sandboxed frame with no
scripts and no access to msgpit. Images the mail carries with it are referenced by `cid:`, which
means nothing to a browser, so msgpit rewrites them to the part they point at. An image the mail
loads from the internet is left alone and will simply not appear when you are offline.

**Text** shows the plain text alternative, when there is one. Worth a look: it is the version that
goes to anyone reading mail without html, and it is the one people forget to keep in step.

**Attachments** lists what was attached, with its type and size, and lets you download it.
Filenames with accents survive.

**Headers** lists every header the sender wrote, not the handful in the summary. This is where
mail analysis actually happens: a missing `Date`, a `Return-Path` that disagrees with `From`, a
`List-Unsubscribe` that never made it in, an `Auto-Submitted` that decides whether an
auto-responder will answer you. The addresses come first, the rest is alphabetical. A `Bcc` is
visible here and nowhere else.

**Source** holds two views of what the message is made of, because it is one question asked twice.

**Message** is the mail exactly as it came off the wire, laid out the way it is actually built:
headers, boundaries, and the bodies in between. Base64 is folded away behind its size, because a
message with an attachment contains a single line of fifteen thousand characters and no amount of
colouring makes that readable. Open it if you want it; **Plain** shows the untouched bytes.

**HTML** is the html as the sender wrote it, which is not always what the preview suggests. Mail
html arrives as a single line, so it is indented and coloured; **Original** shows it exactly as it
came in.

The headers sit above the body, and a mail that carries both an html and a plain text version
gets a switch between them. That plain text alternative is worth checking now and then: it is what
anyone reading mail without html gets, and it is the version people forget to keep in step.

## HTML check

Email clients are a decade behind browsers and disagree with each other, so html that looks right
in the preview can still fall apart in Outlook. The **HTML check** tab scores what the message
uses against what clients actually support.

It walks the document, collects every element, attribute and css property it finds, and looks each
one up. The headline number is the share of tested client versions that support all of it
outright; below it sits the list of features worth a second look, worst first:

```
gap, column-gap, row-gap   css      16 yes   9 partial  17 no
<body> element             html     19 yes  14 partial  16 no
box-shadow                 css      30 yes   4 partial  32 no
display                    css      26 yes  20 partial   0 no
```

That list is the useful part. It does not say your mail is broken; it says which property is the
gamble, so you can decide whether the design is worth it or whether a table would be safer.

Each feature counts once no matter how often it occurs. Weighting by occurrence would flatter a
message that repeats one safe property a hundred times, and the question is not how much safe css
you wrote but whether anything risky is in there at all.

A verdict of "unknown" counts for neither side: nobody measured it, so it is not evidence.

The data comes from [caniemail.com](https://www.caniemail.com) by Rémi Parmentier, MIT licensed,
and is bundled in the image rather than fetched at runtime. msgpit has to work offline, and a
score that silently disappears when the network is down would be worse than one that is a few
weeks old. Refresh it with `php bin/update-caniemail.php` and commit the result.

## Link check

The **Links** tab lists every url in the message: the ones a reader can click, the images and
stylesheets a client fetches by itself, and bare urls in the body text that most clients turn into
links anyway.

Pressing the button fetches them and shows what came back.

**This is the only thing msgpit does that leaves your machine**, and it is deliberately behind a
button. Links in mail often carry a one-shot token: fetching a password reset or an unsubscribe
link can spend it, and a tracking pixel counts the fetch as somebody having read the message. So
it never happens on capture, and never when you simply open a message.

Links inside your own Docker network work too, which makes this useful for the thing that actually
breaks: a mail template that built an url from the wrong host.

One exception: link-local addresses are refused, checked on where the name actually resolves to.
That is where cloud metadata services sit, and they will hand credentials to anything that asks
them. Nothing else on the private network is blocked.

Redirects are reported rather than followed, so you see the chain instead of only its end. Results
are not stored, because they say something about the world right now and not about the message.

## Spam scoring

Point msgpit at a SpamAssassin daemon and every captured mail gets a score, with the rules that
produced it:

```yaml
    environment:
      - MSGPIT_SPAMASSASSIN=spamassassin:783

  spamassassin:
    hostname: spamassassin
    image: instantlinux/spamassassin:latest
```

The **Spam** tab then shows the score against the threshold and a table of what the filter reacted
to. That table is the useful part: a bare 6.2 tells you something is wrong, while

```
+2.7  RISK_FREE        No risk!
+1.5  MONEY_NOHTML     Lots of money in plain text
+1.4  MISSING_DATE     Missing Date: header
```

tells you which sentence in your template to rewrite, and that you forgot a `Date` header.

Scoring is best effort. If the daemon is down, slow or simply not configured, the mail is stored
without a score. A development mail catcher that drops mail because a side service is unhappy
would be worse than one that shows no number.

Note that a message scored in isolation misses things a real mail server would add: there are no
`Received` headers, no SPF or DKIM, and no reputation. Treat the score as a hint about your
content, not as a prediction of what a real filter will do.

## Why the list shows what it shows

For mail the subject is the title and the recipient sits in the preview line, because a list of
identical addresses tells you nothing. For an SMS it is the other way around.

The unread count, the notifications and the filters work the same for mail as for everything else.
Filtering on the channel `email` gives you only mail; `GET /api/messages?channel=email` does the
same over the API.

## Importing a .eml

A message you want to look at is not always one you can send again. A bounce a customer forwarded,
a mail from production, something that landed in a spam folder: drag the `.eml` onto the message
list and msgpit reads it the same way it reads a delivered message. The parts, headers, html check,
link check and spam score all work on it.

**Dragging straight out of macOS Mail does not work, and cannot be made to.** Mail puts a
`message:` url on the drag, which points at the message inside Mail by its Message-ID. No bytes
travel with it and only Mail can resolve it, so there is nothing for a web page to read. msgpit
stops the browser from opening the message in a new tab, says what the drop carried, and points at
the two routes that do work:

- Drag the message from Mail to the Finder or the desktop first. Mail writes a real `.eml` there,
  and that file can be dropped on msgpit.
- Use the **Import .eml** button above the message list, which opens a file picker and takes
  several files at once.

One thing is genuinely different, and the UI says so with an **Imported** label. A delivered message
has an SMTP envelope: the sending server named the sender and every recipient separately, which is
why a Bcc shows up at all. A `.eml` on disk has no envelope, because nobody delivered it. The
recipients therefore come from the `To`, `Cc` and `Bcc` headers, and the sender from `Return-Path`
or `From`. That is the closest thing on record, not the same thing: a mail actually delivered to an
address that appears in no header will not show that address here.

An import is stored under the provider `import` rather than `smtp`, so a test asserting what your
application sent never picks one up by accident.

It is also a single entry in the list, however many addresses are on it. Mail msgpit catches is
stored once per recipient, because a send to three people is three deliveries and each can fail on
its own. An import already arrived, once. Filtering by any one of its recipients still finds it.

```
curl -X POST --data-binary @bounce.eml \
  -H 'X-Msgpit-Filename: bounce.eml' \
  http://msgpit:8080/api/messages/import
```

Files up to 30 MB are accepted.

## What is not there

msgpit accepts a message and stores it. It never delivers, never bounces, and never answers a
delivery status notification. SpamAssassin scoring is a separate feature and is documented with
the rest of the API once it lands.

Every recipient of one mail becomes its own message sharing a `batchId`, and the **envelope**
decides who those recipients are, not the `To` header. That is how delivery actually works, and it
is the only reason a Bcc recipient shows up at all.
