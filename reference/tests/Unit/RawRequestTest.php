<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\RawRequest;
use Msgpit\Http\Request;
use PHPUnit\Framework\TestCase;

final class RawRequestTest extends TestCase
{
    public function testItMasksSecretHeaders(): void
    {
        $raw = RawRequest::fromRequest(new Request(
            method: 'POST',
            path: '/spryng/v2/messages',
            headers: ['x-api-key' => 'abcdefghijklmnop', 'content-type' => 'application/json'],
        ));

        self::assertSame('abcd********mnop', $raw->headers['x-api-key']);
        self::assertSame('application/json', $raw->headers['content-type']);
    }

    public function testAShortSecretIsMaskedEntirely(): void
    {
        $raw = RawRequest::fromRequest(new Request('POST', '/x', ['authorization' => 'Bearer12']));

        self::assertSame('********', $raw->headers['authorization']);
    }

    public function testItRendersAsAnHttpRequest(): void
    {
        $raw = new RawRequest('POST', '/spryng/v2/messages', ['content-type' => 'application/json'], '{"a":1}');

        self::assertSame(
            "POST /spryng/v2/messages HTTP/1.1\nContent-Type: application/json\n\n{\"a\":1}",
            $raw->toText(),
        );
    }
}
