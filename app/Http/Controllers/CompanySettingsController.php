<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanySettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Company/Edit', [
            'company' => $request->user()->company,
        ]);
    }

    public function update(CompanySettingsRequest $request): RedirectResponse
    {
        $request->user()->company->update($request->validated());

        return back()->with('success', 'Настройки компании сохранены.');
    }
}
