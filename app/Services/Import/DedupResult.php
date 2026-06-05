<?php

namespace App\Services\Import;

use App\Models\Tender;

final readonly class DedupResult
{
    public function __construct(
        public Tender $tender,
        public string $status, // created | updated | unchanged
    ) {}
}
