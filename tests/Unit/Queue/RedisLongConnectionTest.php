<?php

it('соединение redis-long существует и имеет retry_after=900', function () {
    $conn = config('queue.connections.redis-long');

    expect($conn)->not->toBeNull()
        ->and($conn['driver'])->toBe('redis')
        ->and($conn['retry_after'])->toBe(900);
});
