<?php

namespace App\Console\Commands;

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisGateway;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Живой смоук-тест ЕИС-шлюза — ОДИН реальный запрос к боевому endpoint через
 * НАСТОЯЩИЙ код SoapEisGateway (не отдельный curl, как probe.sh).
 *
 * Зачем: до любых вложений в прод-инфру/AI-слой доказать, что реальный запрос
 * возвращает данные, а не «гасится». Требует ГОСТ-TLS на curl-стеке PHP
 * (см. scripts/eis-spike/setup-gost.sh) и RU-IP. Запускать на временном RU-VPS.
 *
 * probe.sh проверяет транспорт сырым curl; эта команда — что данные доходят до
 * прод-кода (SOAP-конверт, парсинг archiveUrl/noticeXml). Гонять ПОСЛЕ probe.sh.
 *
 * Выхлоп различает классы отказа:
 *   ConnectionException → транспорт (ГОСТ-handshake / сеть / RU-IP)
 *   RequestException    → HTTP 4xx/5xx (токен / доступ / метод) + тело
 *   200 + пусто         → форма SOAP-конверта vs WSDL (или пустой период)
 */
class EisProbeCommand extends Command
{
    protected $signature = 'eis:probe
        {--reestr= : Реестровый номер — лёгкий вызов getDocsByReestrNumber}
        {--region=77 : Код региона для getDocsByOrgRegion}
        {--subsystem=PRIZ : Подсистема: PRIZ=44-ФЗ, RI223=223-ФЗ}
        {--date= : Дата YYYY-MM-DD (по умолчанию вчера)}';

    protected $description = 'Живой смоук-тест ЕИС: один реальный запрос через SoapEisGateway (RU-IP + ГОСТ-TLS)';

    public function handle(EisGateway $gateway): int
    {
        $endpoint = (string) config('eis.endpoint');
        $token = (string) config('eis.token');

        $this->line("Endpoint:  <info>{$endpoint}</info>");
        if ($token === '') {
            $this->error('EIS_TOKEN не задан в .env — спайк бессмыслен.');

            return self::FAILURE;
        }
        $this->line('Токен:     <info>задан ('.strlen($token).' симв., '.substr($token, 0, 4).'…)</info>');

        try {
            if (is_string($this->option('reestr')) && $this->option('reestr') !== '') {
                return $this->probeReestr($gateway, (string) $this->option('reestr'));
            }

            return $this->probeOrgRegion($gateway);
        } catch (ConnectionException $e) {
            $this->newLine();
            $this->error('❌ ТРАНСПОРТ: соединение не установлено — ГОСТ-TLS / сеть / RU-IP.');
            $this->line('   '.$e->getMessage());
            $this->line('   → Проверь, что PHP-curl слинкован с ГОСТ-openssl (см. setup-gost.sh) и IP из РФ.');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->newLine();
            $status = $e->response->status();
            $this->error("❌ HTTP {$status}: сервер ответил, но отказал — токен/доступ/метод, НЕ транспорт.");
            $this->line('   Тело (первые 2000 симв.):');
            $this->line('   '.mb_substr($e->response->body(), 0, 2000));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('❌ '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function probeReestr(EisGateway $gateway, string $reestr): int
    {
        $this->line("Вызов:     <comment>getDocsByReestrNumber({$reestr})</comment>");
        $t0 = microtime(true);
        $xml = $gateway->fetchByReestrNumber($reestr);
        $ms = (int) ((microtime(true) - $t0) * 1000);
        $this->newLine();

        if ($xml === null || $xml === '') {
            $this->warn("⚠️  200 за {$ms}мс, но noticeXml пуст. Форма SOAP-конверта vs WSDL, либо номер не найден.");

            return self::SUCCESS;
        }

        $this->info("✅ Получен noticeXml ({$ms}мс, ".strlen($xml).' байт). ПАЙП РАБОТАЕТ. 🎉');
        $this->line('   Начало: '.mb_substr($xml, 0, 300));

        return self::SUCCESS;
    }

    private function probeOrgRegion(EisGateway $gateway): int
    {
        $date = is_string($this->option('date')) && $this->option('date') !== ''
            ? (string) $this->option('date')
            : CarbonImmutable::yesterday()->format('Y-m-d');
        $region = (string) $this->option('region');
        $subsystem = (string) $this->option('subsystem');

        $this->line("Вызов:     <comment>getDocsByOrgRegion(region={$region}, subsystem={$subsystem}, date={$date})</comment>");
        $day = CarbonImmutable::parse($date);
        $params = new CollectParams($day, $day, [$region], [$subsystem]);

        $t0 = microtime(true);
        $count = 0;
        foreach ($gateway->listPackages($params) as $ref) {
            $count++;
            $this->line("  пакет: <info>{$ref->archiveUrl}</info>");
            if ($count >= 20) {
                $this->line('  … (обрезано на 20)');
                break;
            }
        }
        $ms = (int) ((microtime(true) - $t0) * 1000);
        $this->newLine();

        if ($count === 0) {
            $this->warn("⚠️  200 за {$ms}мс, но archiveUrl не найдено. Пустой период ИЛИ форма конверта vs WSDL.");

            return self::SUCCESS;
        }

        $this->info("✅ Найдено пакетов: {$count} ({$ms}мс). ПАЙП РАБОТАЕТ. 🎉");

        return self::SUCCESS;
    }
}
