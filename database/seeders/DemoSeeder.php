<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, SourceSeeder::class]);

        $company = Company::updateOrCreate(
            ['inn' => '7700000000'],
            ['name' => 'ООО «Демо Металл»', 'description' => 'Изготовление и монтаж металлоконструкций',
                'notification_email' => 'demo@tender.local', 'is_active' => true],
        );

        $company->subscriptions()->firstOrCreate(
            ['plan_id' => Plan::where('code', 'basic')->value('id')],
            ['status' => SubscriptionStatus::Active, 'started_at' => now()],
        );

        foreach (Source::whereIn('code', ['eis', 'manual'])->get() as $source) {
            CompanySource::firstOrCreate(
                ['company_id' => $company->id, 'source_id' => $source->id],
            );
        }

        User::updateOrCreate(
            ['email' => 'admin@tender.local'],
            ['name' => 'Админ', 'password' => 'password', 'role' => UserRole::Admin, 'company_id' => $company->id],
        );
        User::updateOrCreate(
            ['email' => 'manager@tender.local'],
            ['name' => 'Менеджер', 'password' => 'password', 'role' => UserRole::Manager, 'company_id' => $company->id],
        );
        User::updateOrCreate(
            ['email' => 'root@tender.local'],
            ['name' => 'Суперадмин', 'password' => 'password', 'role' => UserRole::Superadmin, 'company_id' => null],
        );
    }
}
