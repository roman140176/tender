<?php

use App\Enums\RunTrigger;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\Bus;

it('команда collect:source диспатчит CollectSourceJob для существующего источника', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $this->artisan('collect:source', ['code' => 'eis'])
        ->assertExitCode(0);

    Bus::assertDispatched(CollectSourceJob::class, function (CollectSourceJob $job) use ($source) {
        return $job->sourceId === $source->id
            && $job->trigger === RunTrigger::Manual->value;
    });
});

it('команда collect:source завершается с ошибкой для несуществующего источника', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);

    $this->artisan('collect:source', ['code' => 'nonexistent'])
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});
