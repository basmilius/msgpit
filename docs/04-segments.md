# Encoding and segments

An SMS is not billed per message, it is billed per segment. How many segments a message takes
depends on which characters are in it, and the rule is sharper than most people expect: **one
character outside the GSM-7 alphabet quarters the capacity of the entire message.**

msgpit computes this for every captured SMS and shows it in the detail pane, with the offending
characters highlighted.

## The two alphabets

**GSM-7** is the default alphabet. Each character costs 7 bits, so 160 of them fit in one
segment. It covers ASCII plus a specific set of accented characters. Notably it contains `é`,
`ü`, `ñ`, `à`, `ä`, `ö`, `Ç`, `Ø`, `Å`, `Æ`, `ß` and the Greek capitals used in the standard, but
it does **not** contain `á`, `í`, `ó`, `ú`, `â`, `ê`, `ô`, `ç` or the curly quotes a word
processor produces.

That last group is where most surprises come from. `Café` stays GSM-7. `Ñandú` does not, because
of the `ú`. A name pasted from Word with a typographic apostrophe (`’` rather than `'`) does not
either.

**UCS-2** is the fallback. Each character costs 16 bits, so only 70 fit in a segment. As soon as a
single character forces UCS-2, the whole message is encoded that way. There is no mixing.

## The extension table

Nine characters are technically in GSM-7 but cost **two** septets each, because they are preceded
by an escape:

```
^  {  }  \  [  ~  ]  |  €
```

So a message with a euro sign in it is one character longer than it looks. Curly braces in a
template placeholder that never got substituted cost two each as well.

## Segment sizes

| | Single segment | Each segment when concatenated |
|---|---|---|
| GSM-7 | 160 | 153 |
| UCS-2 | 70 | 67 |

The concatenated numbers are smaller because a multipart message carries a header in each segment
saying which part it is. This is why the jump from 160 to 161 characters costs you two segments
and a message of 306 characters still fits in two, but 307 needs three.

A two-unit character is never split across a segment boundary. If a euro sign would straddle the
end of a segment, it moves to the next one whole, and the segment before it is left one septet
short. msgpit accounts for this, which is why its count can differ by one from a naive division.

## Reading the meter

The detail pane shows one bar per segment, each filled to how much of its capacity is used, and a
caption like:

```
GSM-7 · 2 segments · 186 of 306 septets used · 183 characters
```

Characters that forced UCS-2 are highlighted in the body text. If you see a highlight, that single
character is what tripled your bill.

## Practical consequences

A 160 character template is one segment until someone adds an emoji to it, at which point it is
three. Truncating to 160 characters does not help: at UCS-2 the limit is 70.

Variable substitution happens before the count. msgpit stores the body as the recipient would
receive it, so a customer name with an unusual accent shows up as a real encoding change for that
one recipient and not for the others in the same batch.

Providers offer a character set setting (`Auto`, `GSM`, `Unicode` in Spryng). Forcing `GSM` does
not make unicode characters fit; it makes the provider replace or reject them. msgpit always
reports what the text actually needs, which is the number you want to reason about.
