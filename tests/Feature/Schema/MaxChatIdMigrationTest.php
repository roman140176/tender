<?php

use Illuminate\Support\Facades\Schema;

it('миграция add_max_chat_id_to_companies применима и колонка существует', function () {
    // RefreshDatabase (глобальная Feature-регистрация в tests/Pest.php) накатывает
    // все миграции, включая add_max_chat_id_to_companies — колонка должна быть.
    expect(Schema::hasColumn('companies', 'max_chat_id'))->toBeTrue();
});
