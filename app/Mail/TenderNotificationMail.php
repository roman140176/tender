<?php

namespace App\Mail;

use App\Models\TenderMatch;
use App\Notifications\EmailNotificationPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenderNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TenderMatch $match) {}

    public function envelope(): Envelope
    {
        $title = (string) $this->match->tender->title;

        return new Envelope(
            subject: '🔔 Новая релевантная закупка: '.$title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.tender-notification',
            with: EmailNotificationPresenter::data($this->match),
        );
    }
}
