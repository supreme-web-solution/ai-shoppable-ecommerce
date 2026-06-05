@php($reverbClient = \App\Support\ReverbClientConfig::forClient())
@if ($reverbClient)
    <script>
        window.__REVERB__ = @json($reverbClient);
    </script>
@endif
