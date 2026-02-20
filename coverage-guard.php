<?php

declare(strict_types=1);

use ShipMonk\CoverageGuard\Config;
use ShipMonk\CoverageGuard\Rule\EnforceCoverageForMethodsRule;

$config = new Config();

$config->addRule(new EnforceCoverageForMethodsRule(
    requiredCoveragePercentage: 90,
    minExecutableLines: 1,
));

$config->addCoveragePathMapping('/app', __DIR__);

return $config;
