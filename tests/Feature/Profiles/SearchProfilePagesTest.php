<?php

use App\Models\SearchProfile;
use Inertia\Testing\AssertableInertia as Assert;

it('страница списка отдаёт направления с количеством ключей', function () {
    $admin = adminWithPlan();
    SearchProfile::factory()->create(['company_id' => $admin->company_id, 'name' => 'Резка металла']);

    $this->actingAs($admin)
        ->get(route('profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profiles/Index')
            ->has('profiles', 1)
            ->where('profiles.0.name', 'Резка металла')
            ->has('profiles.0.keywords_count'));
});

it('страница редактирования отдаёт профиль, ключи и флаг активации', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->get(route('profiles.edit', $profile))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profiles/Edit')
            ->where('profile.id', $profile->id)
            ->has('keywords')
            ->where('canActivate', false));
});
