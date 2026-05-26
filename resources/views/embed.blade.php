<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $embed->name }} — {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/embed/main.ts'])
    </head>
    <body class="min-h-screen bg-background text-foreground">
        <div
            id="embed-player-app"
            data-embed-slug="{{ $embed->slug }}"
            data-embed-type="{{ $embed->type }}"
            data-embed-name="{{ $embed->name }}"
        ></div>
    </body>
</html>
