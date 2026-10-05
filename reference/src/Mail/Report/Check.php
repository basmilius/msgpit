<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

interface Check
{
    public function run(Context $context): Finding;
}
