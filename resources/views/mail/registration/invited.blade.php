<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Kernel\Localization\LanguageRegistry::class)->direction(app()->getLocale()) }}">
<body style="margin:0;background:#fff8f5;color:#2e2119;font-family:Arial,sans-serif">
<div style="max-width:600px;margin:0 auto;padding:32px 20px">
    <h1 style="color:#6b4226">{{ __('phase15_mail.invited.title', ['center' => $centerName]) }}</h1>
    <p>{{ __('phase15_mail.invited.greeting', ['name' => $ownerName, 'center' => $centerName]) }}</p>
    <p><strong>{{ __('phase15_mail.created.login_email') }}:</strong> {{ $loginEmail }}</p>
    @if ($planName)<p><strong>{{ __('phase15_mail.created.plan') }}:</strong> {{ $planName }}</p>@endif
    <p><a href="{{ $setupUrl }}" style="display:inline-block;background:#b76e79;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none">{{ __('phase15_mail.invited.action') }}</a></p>
    <p style="color:#6b4226">{{ __('phase15_mail.invited.expiry') }}</p>
    <p><strong>{{ __('phase15_mail.created.staff_login') }}:</strong><br><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>
    <p><strong>{{ __('phase15_mail.created.public_website') }}:</strong><br><a href="{{ $publicUrl }}">{{ $publicUrl }}</a></p>
</div>
</body>
</html>
