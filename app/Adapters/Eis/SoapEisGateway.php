<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-транспорт ЕИС через «сервисы отдачи» getDocsIP (SOAP over HTTP).
 * Использует Http-фасад вместо нативного SoapClient для тестируемости.
 *
 * NOTE: Имена SOAP-элементов требуют сверки с актуальной WSDL / Альбомом ТФФ ЕИС
 *       перед первым прод-запуском (last verified: июнь 2026).
 */
class SoapEisGateway implements EisGateway
{
    /**
     * @return iterable<PackageRef>
     */
    public function listPackages(CollectParams $params): iterable
    {
        /** @var array<string, string> $subsystemLawMap */
        $subsystemLawMap = (array) config('eis.subsystem_law_map', []);
        $endpoint = (string) config('eis.endpoint');
        $token = (string) config('eis.token');

        // Итерируем декартово произведение: регион × подсистема × дата
        $current = $params->since->startOfDay();
        $until = $params->until->startOfDay();

        while ($current->lte($until)) {
            $exactDate = $current->format('Y-m-d');

            foreach ($params->regions as $region) {
                foreach ($params->subsystemTypes as $subsystem) {
                    $xml = $this->buildOrgRegionRequest($region, $subsystem, $exactDate);

                    $response = Http::withHeaders([
                        'individualPerson_token' => $token,
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"getDocsByOrgRegion"',
                    ])->withOptions([
                        'allow_redirects' => ['protocols' => ['https']],
                    ])->withBody($xml, 'text/xml')->post($endpoint);

                    // HTTP 4xx/5xx → RequestException, чтобы провижиненный
                    // ретрай джоба (DownloadPackageJob: tries=3) отработал.
                    // Пустой результат со статусом 200 throw() не реагирует.
                    $response->throw();

                    $lawKey = $subsystemLawMap[$subsystem] ?? null;
                    $lawType = $lawKey !== null ? LawType::from($lawKey) : LawType::Other;

                    foreach ($this->extractArchiveUrls($response->body()) as $archiveUrl) {
                        yield new PackageRef(
                            archiveUrl: $archiveUrl,
                            region: $region,
                            subsystemType: $subsystem,
                            date: $exactDate,
                            lawType: $lawType,
                        );
                    }
                }
            }

            $current = $current->addDay();
        }
    }

    public function downloadArchive(PackageRef $ref, string $destAbsolutePath): void
    {
        // SSRF-защита: токен ЕИС форвардится в заголовке individualPerson_token,
        // а archiveUrl приходит из тела SOAP-ответа (недоверенный источник).
        // Без проверки схемы/хоста возможна утечка токена на сторонний хост
        // (Guzzle RedirectMiddleware не вырезает кастомные заголовки) и SSRF.
        $this->assertArchiveUrlAllowed($ref->archiveUrl);

        $dir = dirname($destAbsolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        Http::withHeaders([
            'individualPerson_token' => (string) config('eis.token'),
        ])->withOptions([
            // Лимит хопов + запрет https→http downgrade при редиректах,
            // чтобы токен не утёк на сторонний / незащищённый хост.
            'allow_redirects' => ['max' => 3, 'protocols' => ['https'], 'referer' => false],
        ])->sink($destAbsolutePath)->get($ref->archiveUrl)->throw();
    }

    /**
     * Валидирует archiveUrl: схема https + хост из allowlist (суффикс-матч).
     *
     * @throws \RuntimeException при недопустимой схеме/хосте
     */
    private function assertArchiveUrlAllowed(string $archiveUrl): void
    {
        $scheme = parse_url($archiveUrl, PHP_URL_SCHEME);
        $host = parse_url($archiveUrl, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            throw new \RuntimeException("Недопустимый archiveUrl host/scheme: {$archiveUrl}");
        }

        $host = strtolower($host);

        /** @var list<string> $allowed */
        $allowed = (array) config('eis.allowed_archive_hosts', []);

        foreach ($allowed as $allowedHost) {
            $allowedHost = strtolower(trim((string) $allowedHost));
            if ($allowedHost === '') {
                continue;
            }
            // Точное совпадение ИЛИ поддомен (суффикс с разделителем-точкой).
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return;
            }
        }

        throw new \RuntimeException("Недопустимый archiveUrl host/scheme: {$archiveUrl}");
    }

    public function fetchByReestrNumber(string $regNumber): ?string
    {
        $endpoint = (string) config('eis.endpoint');
        $token = (string) config('eis.token');

        $xml = $this->buildReestrNumberRequest($regNumber);

        $response = Http::withHeaders([
            'individualPerson_token' => $token,
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"getDocsByReestrNumber"',
        ])->withOptions([
            'allow_redirects' => ['protocols' => ['https']],
        ])->withBody($xml, 'text/xml')->post($endpoint);

        // HTTP 4xx/5xx → RequestException (см. listPackages).
        $response->throw();

        return $this->extractNoticeXml($response->body());
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    private function buildOrgRegionRequest(string $region, string $subsystem, string $exactDate): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
              <S:Body>
                <ns2:getDocsByOrgRegionRequest>
                  <ns2:orgRegion>{$region}</ns2:orgRegion>
                  <ns2:subsystemType>{$subsystem}</ns2:subsystemType>
                  <ns2:exactDate>{$exactDate}</ns2:exactDate>
                </ns2:getDocsByOrgRegionRequest>
              </S:Body>
            </S:Envelope>
            XML;
    }

    private function buildReestrNumberRequest(string $regNumber): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
              <S:Body>
                <ns2:getDocsByReestrNumberRequest>
                  <ns2:reestrNumber>{$regNumber}</ns2:reestrNumber>
                </ns2:getDocsByReestrNumberRequest>
              </S:Body>
            </S:Envelope>
            XML;
    }

    /** @return list<string> */
    private function extractArchiveUrls(string $soapResponse): array
    {
        if (trim($soapResponse) === '') {
            return [];
        }

        $doc = new \DOMDocument;
        if (! @$doc->loadXML($soapResponse)) {
            return [];
        }

        // getElementsByTagNameNS('*', ...) — матч по local-name в любом
        // namespace (ловит и <ns3:archiveUrl>, и беспрефиксные). Транспорт
        // namespace-agnostic, как маппер (Task 9/10, xpath local-name()).
        $urls = [];
        foreach ($doc->getElementsByTagNameNS('*', 'archiveUrl') as $node) {
            $url = trim($node->textContent);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function extractNoticeXml(string $soapResponse): ?string
    {
        if (trim($soapResponse) === '') {
            return null;
        }

        $doc = new \DOMDocument;
        if (! @$doc->loadXML($soapResponse)) {
            return null;
        }

        // noticeXml содержит экранированный XML — возвращаем текстовое содержимое.
        // getElementsByTagNameNS('*', ...) — namespace-agnostic (см. extractArchiveUrls).
        foreach ($doc->getElementsByTagNameNS('*', 'noticeXml') as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
