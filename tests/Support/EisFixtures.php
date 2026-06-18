<?php

namespace Tests\Support;

function makeEisArchive(array $xmlAbsolutePaths): string
{
    $dest = tempnam(sys_get_temp_dir(), 'eis_arc_').'.zip';

    $zip = new \ZipArchive;
    if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException("Не удалось создать ZIP-архив: {$dest}");
    }

    foreach ($xmlAbsolutePaths as $absPath) {
        $zip->addFile($absPath, basename($absPath));
    }

    $zip->close();

    return $dest;
}
