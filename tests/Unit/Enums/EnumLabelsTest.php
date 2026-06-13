<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;

it('WorkflowStatus отдаёт русские лейблы для всех 12 статусов', function () {
    foreach (WorkflowStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBe('');
    }
    expect(WorkflowStatus::New->label())->toBe('Новая')
        ->and(WorkflowStatus::HandedOver->label())->toBe('Передана менеджеру')
        ->and(WorkflowStatus::Won->label())->toBe('Выиграли');
});

it('WorkflowStatus::options отдаёт пары value→label для всех кейсов', function () {
    $options = WorkflowStatus::options();
    expect($options)->toHaveCount(count(WorkflowStatus::cases()))
        ->and($options['new'])->toBe('Новая');
});

it('RelevanceLevel отдаёт русские лейблы', function () {
    expect(RelevanceLevel::High->label())->toBe('Высокая')
        ->and(RelevanceLevel::Medium->label())->toBe('Средняя')
        ->and(RelevanceLevel::Weak->label())->toBe('Слабая')
        ->and(RelevanceLevel::Low->label())->toBe('Низкая');
});

it('ActionRecommendation отдаёт русские лейблы (словарь 26.1)', function () {
    expect(ActionRecommendation::HandOver->label())->toBe('Передать менеджеру')
        ->and(ActionRecommendation::ReviewDocs->label())->toBe('Посмотреть ТЗ')
        ->and(ActionRecommendation::Monitor->label())->toBe('Мониторить')
        ->and(ActionRecommendation::Decline->label())->toBe('Отклонить');
});
