<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the owner of a center a Super Admin created: a one-time link to set
 * their own password. It carries no password — there is none to send.
 */
final class CenterOwnerInvitationEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $centerName,
        public readonly string $loginEmail,
        public readonly string $setupUrl,
        public readonly string $loginUrl,
        public readonly string $publicUrl,
        public readonly ?string $planName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('phase15_mail.invited.subject', ['center' => $this->centerName]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.registration.invited');
    }
}
