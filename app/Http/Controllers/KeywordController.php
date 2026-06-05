<?php

namespace App\Http\Controllers;

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KeywordController extends Controller
{
    public function store(Request $request, SearchProfile $profile): RedirectResponse
    {
        $validated = $request->validate([
            'value' => [
                'required', 'string', 'max:255',
                Rule::unique('keywords', 'value')
                    ->where('search_profile_id', $profile->id)
                    ->where('type', $request->input('type')),
            ],
            'type' => ['required', Rule::enum(KeywordType::class)],
        ]);

        $profile->keywords()->create($validated);

        return back()->with('success', 'Ключ добавлен.');
    }

    public function destroy(Keyword $keyword): RedirectResponse
    {
        // Keyword не несёт company_id: принадлежность проверяем через
        // tenant-scope профиля (чужой профиль → null → 404).
        abort_if($keyword->searchProfile()->first() === null, 404);

        $keyword->delete();

        return back()->with('success', 'Ключ удалён.');
    }
}
