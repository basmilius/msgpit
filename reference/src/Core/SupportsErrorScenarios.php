<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Http\Response;

interface SupportsErrorScenarios
{
    public function errorResponse(Scenario $scenario): Response;
}
