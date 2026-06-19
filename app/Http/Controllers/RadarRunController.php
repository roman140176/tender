<?php

namespace App\Http\Controllers;

use App\Enums\RunTrigger;
use App\Jobs\MatchProfileJob;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;

class RadarRunController extends Controller
{
    /** Запуск матчинга по одному направлению текущей компании. */
    public function trigger(SearchProfile $profile): RedirectResponse
    {
        MatchProfileJob::dispatch($profile->id, RunTrigger::Manual->value, null);

        return redirect()->back()->with('success', "Радар по направлению «{$profile->name}» запущен.");
    }

    /** Запуск матчинга по всем активным направлениям текущей компании. */
    public function triggerCompany(): RedirectResponse
    {
        $ids = SearchProfile::query()
            ->where('is_active', true)
            ->pluck('id');

        foreach ($ids as $id) {
            MatchProfileJob::dispatch($id, RunTrigger::Manual->value, null);
        }

        $count = $ids->count();

        return redirect()->back()->with(
            'success',
            $count > 0
                ? "Радар запущен по {$count} активным направлениям."
                : 'Нет активных направлений для запуска радара.'
        );
    }
}
