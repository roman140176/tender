<?php

use App\Adapters\Eis\EisAdapter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')
            ->where('code', 'eis')
            ->update([
                'adapter_class' => EisAdapter::class,
                'schedule_cron' => '0 */4 * * *',
            ]);
    }

    public function down(): void
    {
        DB::table('sources')
            ->where('code', 'eis')
            ->update([
                'adapter_class' => null,
                'schedule_cron' => null,
            ]);
    }
};
