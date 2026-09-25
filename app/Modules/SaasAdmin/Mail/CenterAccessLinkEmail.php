<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A one-time "set a new password" link sent on a center user's behalf by Meta Style support. */
final class CenterAccessLinkEmail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $centerName,
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('phase15_mail.access_link.subject', ['center' => $this->centerName]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.identity.access-link');
    }
}
