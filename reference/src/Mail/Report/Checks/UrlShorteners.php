<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;
use Msgpit\Mime\Links;

/**
 * A shortener hides where a link goes, which is why spam uses them and why filters treat them as
 * a signal in their own right. The list is short on purpose: these are the ones that actually
 * turn up in mail, and a wrong entry costs a message points it did not earn.
 */
final readonly class UrlShorteners implements Check
{
    private const SHORTENERS = [
        'bit.ly', 'bit.do', 't.co', 'tinyurl.com', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly',
        'rebrand.ly', 'cutt.ly', 'shorturl.at', 't.ly', 'rb.gy', 'lnkd.in', 's.id', 'tiny.cc',
        'shorte.st', 'adf.ly', 'soo.gd', 'clck.ru', 'trib.al', 'mcaf.ee', 'qr.ae',
    ];

    public function run(Context $context): Finding
    {
        $found = [];

        foreach (Links::find($context->html, $context->text) as $link) {
            $host = parse_url($link['url'], PHP_URL_HOST);

            if (!is_string($host)) {
                continue;
            }

            $host = preg_replace('/^www\./', '', strtolower($host));

            if (in_array($host, self::SHORTENERS, true)) {
                $found[$link['url']] = $link['url'];
            }
        }

        if ($found === []) {
            return Finding::pass('url-shorteners', Section::Links, 'No shortened urls');
        }

        return Finding::warn(
            'url-shorteners',
            Section::Links,
            count($found) . ' links use a url shortener',
            1.0,
            'A shortener hides the destination, and filters score it the way they score anything that does.',
            array_slice(array_values($found), 0, 10),
        );
    }
}
