# Why msgpit exists

Sending an SMS costs money and reaches a real phone. So during development you do not send one.
What teams usually do instead falls into a few patterns, and all of them have the same problem.

**A null driver that logs and returns.** Cheap, and it hides everything that matters. Your code
takes a different path than it does in production, so the parts that actually break (a rejected
sender id, a body that silently became two segments, the delivery webhook you never tested) stay
invisible until they break for a customer.

**The provider's sandbox.** Closer to real, but it needs credentials, it needs network, it is
shared with colleagues, and you cannot make it fail on demand. Rate limiting in particular is
something you can only wait for, never provoke.

**Mocking the HTTP client in tests.** Fine for unit tests, useless while you are clicking through
a feature by hand.

msgpit takes the approach Mailpit took for email. Your application keeps calling the provider SDK
exactly as it does in production, with the same client, the same payload and the same error
handling. **Only the base URL differs per environment.** msgpit accepts the request, stores it,
answers the way the real provider would, and shows you everything in a browser.

## What that buys you

You see the request your code actually sent, not the one you think it sent. Headers included,
secrets masked.

You see what an SMS costs before it costs it. One emoji in a template pushes the whole message
from GSM-7 to UCS-2 and cuts the capacity from 160 characters to 70. A 150 character message
quietly becoming three segments is a real bill and an invisible bug. msgpit shows the encoding,
the segment count and highlights the exact character responsible.

You can test the unhappy path. Rate limits, invalid numbers, provider outages, rejected
credentials: all reachable on demand, either by sending to a magic number or by arming a one-shot
toggle. See [Failure scenarios](#docs/03-scenarios).

You get told when something arrives. The unread count sits in the tab title, and over https a
desktop notification can announce a captured message while you are working somewhere else.

You can test delivery reports without a public URL or a tunnel. Mark a message delivered and
msgpit calls your webhook with the payload the provider would send, then shows you what your app
answered.

Your integration tests get an assertion target. Send, then ask msgpit whether the message exists
and what was in it. See [HTTP API](#docs/06-api).

## Mail too

The same reasoning applies to email, and for a long time the answer was Mailpit. It still is a
fine tool, but running one catcher for mail and another for messages means two places to look,
two APIs to assert against, and two things to explain to a new colleague. msgpit listens on SMTP
as well, so there is one inbox for everything an application sends. See
[Email](#docs/07-email).

## Design decisions

**Nothing is ever delivered.** The only outbound HTTP request msgpit makes is the delivery-report
callback to your own application. There is no code path that talks to a provider.

**Credentials are never validated.** msgpit checks that authentication has the right *shape*: the
header the provider expects, present and non-empty. It never looks at the value, so you can put
anything in your local configuration. A missing header does return the provider's real 401,
because forgetting to configure authentication is a mistake worth catching locally.

**Every provider is a plugin.** The core knows about messages, channels, segments and storage. It
never references a concrete provider. Adding one is a new class and a registry entry, never a core
change.

**No dependencies, no build step.** The runtime registers its own autoloader and needs no
`vendor/`. The UI is plain JavaScript and CSS. This keeps the image tiny and means you can mount
the source over the container and edit it live.

**Losing the data is fine.** Storage is a SQLite file. If the container resets, your captured
messages are gone, and that is the intended trade-off for a tool that holds throwaway test data.

## What it is not

No real delivery, ever. No inbound messages. No multi-user, no authentication on the UI, no
persistence guarantees. No full API coverage per provider: only the endpoints applications
actually call.
