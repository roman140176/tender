<?php

namespace App\Notifications\Channels;

use App\Mail\TenderNotificationMail;
use App\Models\Company;
use App\Models\TenderMatch;
use Illuminate\Support\Facades\Mail;

/**
 * Email-канал. recipient = companies.notification_email, доставка через Mailable.
 */
final class EmailChannelNotifier implements ChannelNotifier
{
    public function recipientFor(Company $company): ?string
    {
        return $company->notification_email;
    }

    public function send(string $recipient, TenderMatch $match): void
    {
        Mail::to($recipient)->send(new TenderNotificationMail($match));
    }
}
