<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;

final readonly class PackageRef
{
    public function __construct(
        public string $archiveUrl,
        public string $region,
        public string $subsystemType,
        public string $date,
        public LawType $lawType,
    ) {}
}
