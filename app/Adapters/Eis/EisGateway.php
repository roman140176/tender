<?php

namespace App\Adapters\Eis;

interface EisGateway
{
    /**
     * @return iterable<PackageRef>
     */
    public function listPackages(CollectParams $params): iterable;

    public function downloadArchive(PackageRef $ref, string $destAbsolutePath): void;

    /** Raw XML string or null if not found. */
    public function fetchByReestrNumber(string $regNumber): ?string;
}
