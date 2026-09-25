<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CenterPasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $name, public readonly string $centerName, public readonly string $resetUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('phase15_mail.password_reset.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.identity.center-password-reset');
    }
}
