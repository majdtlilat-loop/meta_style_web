<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Kernel\Localization\LanguageRegistry::class)->direction(app()->getLocale()) }}">
<body style="margin:0;background:#fff8f5;color:#2e2119;font-family:Arial,sans-serif">
<div style="max-width:600px;margin:0 auto;padding:32px 20px">
    <h1 style="color:#6b4226">{{ __('phase15_mail.access_link.title') }}</h1>
    <p>{{ __('phase15_mail.access_link.greeting', ['name' => $name, 'center' => $centerName]) }}</p>
    <p><a href="{{ $resetUrl }}" style="display:inline-block;background:#b76e79;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none">{{ __('phase15_mail.access_link.action') }}</a></p>
    <p>{{ __('phase15_mail.access_link.expiry') }}</p>
    <p>{{ __('phase15_mail.password_reset.ignore') }}</p>
</div>
</body>
</html>
