<?php

namespace App\Adapters\Eis;

use App\Adapters\Contracts\TenderSourceAdapter;
use App\Enums\LawType;
use App\Services\Import\NormalizedTender;

/**
 * Фасад ЕИС-адаптера.
 * Потоковый разбор ZIP+XML извещений ЕИС: ZipArchive → XMLReader по узлам,
 * пик памяти = одно извещение (не весь файл).
 */
class EisAdapter implements TenderSourceAdapter
{
    public function __construct(
        private readonly EisGateway $gateway,
        private readonly EisNoticeMapper $mapper,
    ) {}

    public function sourceCode(): string
    {
        return 'eis';
    }

    /**
     * Потоковое извлечение извещений из ZIP-архива.
     * Паттерн: ZipArchive → каждый .xml → временный файл → XMLReader по узлам.
     * Пик памяти = одно извещение (не весь файл).
     *
     * @return iterable<RawTender>
     */
    public function streamNotices(string $zipAbsolutePath, LawType $law): iterable
    {
        $lawKey = $law->value;
        $noticeRoots = (array) (config('eis.notice_roots')[$lawKey] ?? []);

        $zip = new \ZipArchive;
        if ($zip->open($zipAbsolutePath) !== true) {
            return;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || ! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }

                // Извлечь один XML во временный файл (потоковый, не loadXML всего)
                $tmpFile = tempnam(sys_get_temp_dir(), 'eis_xml_');
                if ($tmpFile === false) {
                    continue;
                }

                try {
                    $stream = $zip->getStream($name);
                    if ($stream === false) {
                        continue;
                    }

                    $fp = fopen($tmpFile, 'wb');
                    if ($fp === false) {
                        fclose($stream);

                        continue;
                    }
                    try {
                        while (! feof($stream)) {
                            fwrite($fp, (string) fread($stream, 65536));
                        }
                    } finally {
                        fclose($fp);
                        fclose($stream);
                    }

                    // XMLReader — потоковое чтение по узлам
                    yield from $this->readNoticesFromFile($tmpFile, $law, $noticeRoots);
                } finally {
                    @unlink($tmpFile);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  list<string>  $noticeRoots
     * @return iterable<RawTender>
     */
    private function readNoticesFromFile(string $filePath, LawType $law, array $noticeRoots): iterable
    {
        $reader = new \XMLReader;
        if (! $reader->open('file://'.$filePath)) {
            return;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                // localName — без префикса namespace
                if (! in_array($reader->localName, $noticeRoots, true)) {
                    continue;
                }

                // Извлекаем одно поддерево как XML-строку и грузим в собственный документ.
                // Маппер namespace-agnostic (xpath local-name()), поэтому потеря префиксов
                // некритична — важно лишь целостное поддерево.
                $xml = $reader->readOuterXml();
                if ($xml === '') {
                    continue;
                }

                $doc = new \DOMDocument;
                if (! @$doc->loadXML($xml)) {
                    continue;
                }

                $root = $doc->documentElement;
                if ($root === null) {
                    continue;
                }

                yield $this->mapper->extract($root, $law);
            }
        } finally {
            $reader->close();
        }
    }

    // -------------------------------------------------------------------------
    // Полная реализация методов адаптера (binding EisGateway→SoapEisGateway добавляется в Task 13; здесь — НЕ заглушки)
    // -------------------------------------------------------------------------

    /** @return iterable<PackageRef> */
    public function listPackages(CollectParams $params): iterable
    {
        yield from $this->gateway->listPackages($params);
    }

    public function downloadArchive(PackageRef $ref, string $destAbsolutePath): void
    {
        $this->gateway->downloadArchive($ref, $destAbsolutePath);
    }

    public function normalize(RawTender $raw): NormalizedTender
    {
        return $this->mapper->toNormalizedTender($raw);
    }

    public function details(string $externalId): ?RawTenderDetails
    {
        $xml = $this->gateway->fetchByReestrNumber($externalId);
        if ($xml === null) {
            return null;
        }

        // Определяем lawType из root-элемента XML
        $doc = new \DOMDocument;
        if (! @$doc->loadXML($xml)) {
            return null;
        }

        $rootEl = $doc->documentElement->localName ?? '';
        $lawType = $this->resolveLawTypeFromRoot($rootEl);
        if ($lawType === null) {
            return null;
        }

        $raw = $this->mapper->extract($doc->documentElement, $lawType);

        return new RawTenderDetails(raw: $raw, rawXml: $xml);
    }

    /**
     * @return iterable<RawTender>
     */
    public function collect(CollectParams $params): iterable
    {
        foreach ($this->gateway->listPackages($params) as $ref) {
            $dest = sys_get_temp_dir().'/eis_collect_'.md5($ref->archiveUrl).'.zip';
            try {
                $this->gateway->downloadArchive($ref, $dest);
                yield from $this->streamNotices($dest, $ref->lawType);
            } finally {
                @unlink($dest);
            }
        }
    }

    // -------------------------------------------------------------------------

    private function resolveLawTypeFromRoot(string $rootElement): ?LawType
    {
        /** @var array<string, list<string>> $noticeRoots */
        $noticeRoots = (array) config('eis.notice_roots', []);

        foreach ($noticeRoots as $lawKey => $roots) {
            if (in_array($rootElement, $roots, true)) {
                return LawType::from($lawKey);
            }
        }

        return null;
    }
}
