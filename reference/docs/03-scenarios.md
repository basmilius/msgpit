# Failure scenarios

The happy path is the easy half. What breaks in production is the other half: a number that turns
out to be a landline, an API key that expired, a provider having a bad morning, a rate limit you
only hit once you have real volume.

msgpit makes all of those reachable on demand. There are two ways to trigger one, and they are
provider-agnostic: the core decides *that* a request fails, the provider decides what the failure
*looks like*. So a rate limit in Spryng comes back as Spryng's own 429 body, with the header it
really sends.

## Magic recipient numbers

Send to one of these numbers and the request fails instead of being stored. Nothing else changes:
same endpoint, same payload, same SDK.

<!-- scenarios -->

The numbers live in `Core\Scenario`, in one place, so every provider gets the same set. The table
above is read from the running application, so it always matches the code.

Formatting does not matter: `+31600000001`, `0031600000001` and `+31 600 000 001` all work.

**A magic number fails the whole request.** If you send to three recipients and one of them is
magic, nothing is stored and your application gets an error. That mirrors providers that validate
the entire request before accepting any of it. If you want two recipients to succeed and one to
fail, send them as separate requests.

## One-shot toggle

Sometimes the number matters: you want to see your own retry logic handle a rate limit on a real
recipient. Pick a scenario in the toolbar and the **next** provider request fails, whatever it
contains. The toggle then clears itself.

Through the API:

```bash
curl -X POST http://msgpit:8080/api/scenario \
  -H 'Content-Type: application/json' \
  -d '{"scenario":"RateLimited"}'
```

Pass `{"scenario":null}` to disarm it.

The toggle is only consumed by a provider that supports scenarios. If you arm it and then call a
provider that does not, that request is answered normally and the toggle stays armed for the next
one that does.

## What gets stored

Nothing. A failed request is not written to the database and does not appear in the UI. As far as
your application is concerned the message never landed, which is exactly what a real failure looks
like. If you need to see what you sent, look at your own logs, or send it once without a scenario
first.

## Providers without scenario support

Scenarios only apply to providers implementing `SupportsErrorScenarios`. A provider that does not
implement it ignores both the magic numbers and the toggle, and stores the message normally. The
capability is visible per provider in `GET /api/providers`.
