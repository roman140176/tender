<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchProfileRequest;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SearchProfileController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Profiles/Index', [
            'profiles' => SearchProfile::query()
                ->withCount('keywords')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Profiles/Create');
    }

    public function store(SearchProfileRequest $request): RedirectResponse
    {
        $profile = SearchProfile::create($request->validated());

        return redirect()
            ->route('profiles.edit', $profile)
            ->with('success', 'Направление создано. Добавьте ключевые слова и активируйте его.');
    }

    public function edit(SearchProfile $profile): Response
    {
        return Inertia::render('Profiles/Edit', [
            'profile' => $profile,
            'keywords' => $profile->keywords()->orderBy('type')->orderBy('value')->get(),
            'canActivate' => $profile->canActivate(),
        ]);
    }

    public function update(SearchProfileRequest $request, SearchProfile $profile): RedirectResponse
    {
        $profile->update($request->validated());

        return redirect()->route('profiles.edit', $profile)->with('success', 'Сохранено.');
    }

    public function destroy(SearchProfile $profile): RedirectResponse
    {
        $profile->delete();

        return redirect()->route('profiles.index')->with('success', 'Направление удалено.');
    }
}
