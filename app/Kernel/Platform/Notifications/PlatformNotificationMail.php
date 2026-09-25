<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Notifications;

use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A platform notification, emailed to the platform staff who may see it. */
final class PlatformNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     */
    public function __construct(
        public readonly array $title,
        public readonly array $body,
        public readonly ?string $actionPath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->localized($this->title));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.platform.notification', with: [
            'heading' => $this->localized($this->title),
            'message' => $this->localized($this->body),
            'actionUrl' => $this->actionPath !== null ? app(PlatformHosts::class)->superAdminUrl($this->actionPath) : null,
        ]);
    }

    /** @param array<string, string> $values */
    private function localized(array $values): string
    {
        return $values[app()->getLocale()] ?? $values['en'] ?? '';
    }
}
