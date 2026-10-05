<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

enum BlocklistVerdict: string
{
    case Clean = 'clean';
    case Good = 'good';

    /** Listed, but on a list that means something milder than "this sends spam". */
    case Caution = 'caution';
    case Listed = 'listed';

    /** The list declined to answer, which is what happens through a public resolver. */
    case Refused = 'refused';
}
