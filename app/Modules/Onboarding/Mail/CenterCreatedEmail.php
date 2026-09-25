<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CenterCreatedEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $centerName,
        public readonly string $loginEmail,
        public readonly string $publicUrl,
        public readonly string $loginUrl,
        public readonly ?string $planName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('phase15_mail.created.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.registration.created');
    }
}
