<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * Most clients block remote images until the reader asks for them, so the alt text is what the
 * message says on arrival. It is also what a screen reader has to work with.
 */
final readonly class ImageAlt implements Check
{
    public function run(Context $context): Finding
    {
        $document = $context->document();

        if ($document === null) {
            return Finding::skip('image-alt', Section::Content, 'Images have alt text', 'The message has no html.');
        }

        $images = $document->getElementsByTagName('img');

        if ($images->length === 0) {
            return Finding::pass('image-alt', Section::Content, 'The message has no images');
        }

        $missing = [];

        foreach ($images as $image) {

            // An empty alt is a deliberate "this is decoration" and counts as answered.
            if (!$image->hasAttribute('alt')) {
                $missing[] = $image->getAttribute('src') ?: '(image without src)';
            }
        }

        if ($missing === []) {
            return Finding::pass(
                'image-alt',
                Section::Content,
                $images->length === 1 ? 'The image has alt text' : "All {$images->length} images have alt text",
            );
        }

        return Finding::warn(
            'image-alt',
            Section::Content,
            count($missing) . ' of ' . $images->length . ' images have no alt text',
            0.5,
            'With images blocked, those are blank spaces.',
            array_slice($missing, 0, 10),
        );
    }
}
