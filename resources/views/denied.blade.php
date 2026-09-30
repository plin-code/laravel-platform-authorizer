<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('platform-authorizer::messages.denied_title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; color: #1f2937; background: #f9fafb; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('platform-authorizer::messages.denied_title') }}</h1>
        <p>{{ __('platform-authorizer::messages.denied_body') }}</p>
        <p><a href="{{ $link }}">{{ __('platform-authorizer::messages.denied_link') }}</a></p>
    </main>
</body>
</html>
