<?php

declare(strict_types=1);

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\SaaS\Models\Registration;
use App\Modules\Identity\Mail\CenterPasswordResetMail;
use App\Modules\Onboarding\Mail\CenterCreatedEmail;
use App\Modules\Onboarding\Mail\VerifyCenterEmail;

it('renders every Phase 15 account email in each supported locale and direction', function (string $locale): void {
    app()->setLocale($locale);

    $direction = app(LanguageRegistry::class)->direction($locale);
    $registration = new Registration;
    $registration->forceFill([
        'owner_name' => 'Test Owner',
        'center_name' => 'Test Center',
        'verification_expires_at' => now()->addHour(),
    ]);

    $mailables = [
        [
            new VerifyCenterEmail($registration, 'https://example.invalid/verify'),
            'phase15_mail.verification.subject',
            'phase15_mail.verification.title',
        ],
        [
            new CenterCreatedEmail(
                ownerName: 'Test Owner',
                centerName: 'Test Center',
                loginEmail: 'owner@example.invalid',
                publicUrl: 'https://center.example.invalid',
                loginUrl: 'https://center.example.invalid/login',
                planName: 'Trial',
            ),
            'phase15_mail.created.subject',
            'phase15_mail.created.title',
        ],
        [
            new CenterPasswordResetMail('Test Owner', 'Test Center', 'https://center.example.invalid/reset-password/token'),
            'phase15_mail.password_reset.subject',
            'phase15_mail.password_reset.title',
        ],
    ];

    foreach ($mailables as [$mailable, $subjectKey, $titleKey]) {
        $mailable->locale($locale);
        $html = $mailable->render();

        expect($mailable->envelope()->subject)->toBe(__($subjectKey))
            ->and($html)->toContain('<html lang="'.$locale.'" dir="'.$direction.'">')
            ->toContain(__($titleKey))
            ->not->toContain('correct-horse-battery-staple')
            ->not->toContain('MetaStyle@123456');
    }
})->with(['en', 'ar', 'ckb']);
