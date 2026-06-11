<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

/** @psalm-api */
final readonly class ParamMetadata
{
    public const string KIND_CONTAINER = 'container';
    public const string KIND_FROM_ACTOR = 'from_actor';
    public const string KIND_FROM_BODY = 'from_body';
    public const string KIND_FROM_SERVICE = 'from_service';
    public const string KIND_PATH_PARAM = 'path_param';
    public const string KIND_REQUEST_SCOPE = 'request_scope';
    public const string KIND_SERVER_REQUEST = 'server_request';

    public function __construct(
        public string $name,
        public ?string $type,
        public string $kind,
        public ?string $actorName = null,
        public ?string $serviceId = null,
    ) {}
}
