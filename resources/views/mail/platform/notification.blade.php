<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Kernel\Localization\LanguageRegistry::class)->direction(app()->getLocale()) }}">
<body style="margin:0;background:#fff8f5;color:#2e2119;font-family:Arial,sans-serif">
<div style="max-width:600px;margin:0 auto;padding:32px 20px">
    <p style="color:#b76e79;font-weight:bold;margin:0 0 8px">Meta Style</p>
    <h1 style="color:#6b4226;font-size:22px">{{ $heading }}</h1>
    <p style="line-height:1.6">{{ $message }}</p>
    @if ($actionUrl)
        <p><a href="{{ $actionUrl }}" style="display:inline-block;background:#b76e79;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none">{{ __('platform_notifications.mail.open') }}</a></p>
    @endif
</div>
</body>
</html>
