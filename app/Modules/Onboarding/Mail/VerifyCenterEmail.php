<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Mail;

use App\Kernel\SaaS\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class VerifyCenterEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Registration $registration, public readonly string $verificationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('phase15_mail.verification.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.registration.verify');
    }
}
