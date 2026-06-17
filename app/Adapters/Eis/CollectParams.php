<?php

namespace App\Adapters\Eis;

use Carbon\CarbonImmutable;

final readonly class CollectParams
{
    /**
     * @param  list<string>  $regions
     * @param  list<string>  $subsystemTypes
     */
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
        public array $regions,
        public array $subsystemTypes,
    ) {}
}
