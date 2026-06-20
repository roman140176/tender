<?php

namespace App\Notifications\Channels;

use App\Models\Company;
use App\Models\TenderMatch;
use App\Notifications\MaxGateway;
use App\Notifications\MaxNotificationPresenter;

/**
 * MAX-канал. Поведение НЕ меняется относительно прежнего инлайна в NotifyJob:
 * recipient = companies.max_chat_id, доставка = MaxGateway->sendMessage(HTML, []).
 */
final class MaxChannelNotifier implements ChannelNotifier
{
    public function __construct(private readonly MaxGateway $gateway) {}

    public function recipientFor(Company $company): ?string
    {
        return $company->max_chat_id;
    }

    public function send(string $recipient, TenderMatch $match): void
    {
        $text = MaxNotificationPresenter::render($match);

        $this->gateway->sendMessage($recipient, $text, []);
    }
}
