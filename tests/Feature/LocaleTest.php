<?php

it('валидационные сообщения на русском', function () {
    $response = $this->post('/login', ['email' => 'not-an-email', 'password' => '']);

    $response->assertSessionHasErrors(['email', 'password']);
    expect(session('errors')->first('password'))->toContain('обязательно');
});
