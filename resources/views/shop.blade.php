<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#000000">
        <title>{{ $embed->name }} — Shop</title>
        @vite(['resources/css/app.css', 'resources/js/embed/main.ts'])
        <style>
            html, body { margin: 0; height: 100%; overflow: hidden; background: #000; }
            #embed-player-app { min-height: 100dvh; height: 100dvh; }
        </style>
    </head>
    <body class="bg-black text-white">
        <div
            id="embed-player-app"
            class="shop-landing"
            data-embed-slug="{{ $embed->slug }}"
            data-embed-type="vertical_feed"
            data-embed-name="{{ $embed->name }}"
            data-shop-landing="1"
        ></div>
    </body>
</html>
