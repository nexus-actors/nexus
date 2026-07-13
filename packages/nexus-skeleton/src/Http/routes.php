<?php

declare(strict_types=1);

use App\Http\HelloController;
use Monadial\Nexus\Http\Dsl\HttpApp;

return static fn (HttpApp $app) => $app->get('/', HelloController::class);
