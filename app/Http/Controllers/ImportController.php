<?php

namespace App\Http\Controllers;

use App\Models\SourceCollectRun;
use App\Services\Import\ManualImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Imports/Index', [
            'runs' => SourceCollectRun::query()
                ->where('company_id', $request->user()->company_id)
                ->latest('started_at')
                ->limit(20)
                ->get(['id', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'updated_count', 'error_count', 'log']),
            'maxFileSizeKb' => (int) config('import.max_file_size_kb'),
            'maxRows' => (int) config('import.max_rows'),
        ]);
    }

    public function store(Request $request, ManualImportService $service): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required', 'file',
                'mimes:csv,txt,xlsx,json',
                'max:'.config('import.max_file_size_kb'),
            ],
        ], [
            'file.mimes' => 'Поддерживаются файлы CSV, XLSX и JSON.',
            'file.max' => 'Файл больше '.round(config('import.max_file_size_kb') / 1024).' МБ.',
        ]);

        $run = $service->import($request->file('file'), $request->user()->company);

        $message = "Импорт завершён: новых {$run->new_count}, обновлено {$run->updated_count}, ошибок {$run->error_count}.";

        return redirect()->route('imports.index')->with($run->error_count > 0 ? 'error' : 'success', $message);
    }

    public function template(): StreamedResponse
    {
        $headers = "Название;Описание;Заказчик;ИНН заказчика;Регион;Цена;Валюта;Закон;Тип процедуры;Статус;Дата публикации;Дедлайн;Ссылка;Номер;ОКПД2\n";
        $example = "Поставка металлоконструкций;По чертежам КМД;ГБУ Мосты;7701234567;Москва;1200000,50;RUB;44-ФЗ;Аукцион;Активна;01.06.2026;20.06.2026;https://zakupki.gov.ru/...;0173200001;25.11\n";

        return response()->streamDownload(
            fn () => print ("\u{FEFF}".$headers.$example), // BOM — чтобы Excel открыл UTF-8 корректно
            'tender-import-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
