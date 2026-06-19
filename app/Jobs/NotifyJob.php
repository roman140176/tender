<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\RadarRun;
use App\Models\TenderMatch;
use App\Notifications\MaxGateway;
use App\Notifications\MaxNotificationPresenter;
use App\Services\Notification\NotificationDecider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotifyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $tenderMatchId)
    {
        $this->onConnection('notifications')->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(MaxGateway $gateway, NotificationDecider $decider): void
    {
        /** @var TenderMatch|null $match */
        $match = TenderMatch::withoutTenancy()
            ->with(['tender.source', 'searchProfile'])
            ->find($this->tenderMatchId);

        if ($match === null) {
            return; // матч удалён между диспатчем и обработкой
        }

        // TenderMatch не имеет связи company() — грузим компанию явно
        // (Company без BelongsToCompany-trait, scope не применяется).
        $company = Company::find($match->company_id);
        $chatId = $company?->max_chat_id;
        if ($chatId === null || $chatId === '') {
            $this->logFailed($match, null, 'no chat_id');

            return;
        }

        if (! $decider->shouldNotify($match)) {
            return; // антидубль §9 — без лога-дубля
        }

        $text = MaxNotificationPresenter::render($match);

        try {
            $gateway->sendMessage((string) $chatId, $text, []);
        } catch (Throwable $e) {
            $this->logFailed($match, (string) $chatId, $e->getMessage());

            throw $e; // проброс → ретрай джоба
        }

        $this->logSent($match, (string) $chatId);
        $this->incrementNotified($match);
    }

    private function logSent(TenderMatch $match, string $chatId): void
    {
        $tender = $match->tender;

        NotificationLog::create([
            'company_id' => $match->company_id,
            'tender_id' => $match->tender_id,
            'search_profile_id' => $match->search_profile_id,
            'channel' => NotificationChannel::Max,
            'recipient' => $chatId,
            'status' => NotificationStatus::Sent,
            'payload' => [
                'price' => $tender->price,
                'deadline_at' => $tender->deadline_at?->toIso8601String(),
                'source_status' => $tender->source_status->value,
                'score' => $match->score,
            ],
            'sent_at' => now(),
        ]);
    }

    private function logFailed(TenderMatch $match, ?string $chatId, string $error): void
    {
        NotificationLog::create([
            'company_id' => $match->company_id,
            'tender_id' => $match->tender_id,
            'search_profile_id' => $match->search_profile_id,
            'channel' => NotificationChannel::Max,
            'recipient' => (string) ($chatId ?? ''),
            'status' => NotificationStatus::Failed,
            'payload' => [],
            'error' => mb_substr($error, 0, 1000),
        ]);
    }

    private function incrementNotified(TenderMatch $match): void
    {
        if ($match->radar_run_id === null) {
            return;
        }

        RadarRun::withoutTenancy()
            ->whereKey($match->radar_run_id)
            ->update(['notified_count' => DB::raw('notified_count + 1')]);
    }
}
