<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A one-time link for a platform user to set their password: an invitation
 * for someone new, or a reset. It never contains a password.
 */
final class PlatformAccessEmail extends Mailable implements ShouldQueue
{
    use Queueable;

    /** @param 'invite'|'reset' $kind */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('phase15_mail.platform_access.'.$this->kind.'_subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.platform.access');
    }
}
