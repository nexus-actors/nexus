<?php

declare(strict_types=1);

use App\Support\Recorder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $c): void {
    $services = $c->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__ . '/../src/')
        ->exclude([__DIR__ . '/../src/Kernel.php']);

    $services->set(Recorder::class)->public();
};
