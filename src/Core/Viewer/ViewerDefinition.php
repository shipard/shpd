<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

class ViewerDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $table,
        public readonly ?string $class,
        public readonly string $moduleId,
    ) {}
}
