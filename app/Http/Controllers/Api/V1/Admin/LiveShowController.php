<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLiveShowRequest;
use App\Http\Resources\Api\V1\LiveShowResource;
use App\Jobs\RefreshKnowledgeEmbeddingsJob;
use App\Models\LiveShow;
use App\Models\LiveShowMessage;
use App\Models\LiveShowRegistration;
use App\Services\Integrations\DailyService;
use App\Services\Integrations\RestreamService;
use App\Services\LiveBroadcast\LiveBroadcastIngestService;
use App\Services\LiveBroadcast\LiveBroadcastSessionService;
use Illuminate\Support\Facades\Log;
use App\Services\Webinars\WebinarAttendeeService;
use App\Services\Webinars\WebinarOfferService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class LiveShowController extends Controller
{
    protected function resolveTeamId(Request $request): int
    {
        $teamId = $request->integer('team_id', $request->user()->team_id);
        abort_unless($request->user()->team_id === $teamId || $request->user()->teams()->whereKey($teamId)->exists(), 403);

        return $teamId;
    }

    public function index(Request $request)
    {
        $teamId = $this->resolveTeamId($request);

        $liveShows = LiveShow::query()
            ->where('team_id', $teamId)
            ->with(['featuredProducts', 'video', 'team'])
            ->withCount([
                'registrations',
                'messages',
                'registrations as conversations_count' => fn ($query) => $query->whereHas('messages'),
                'registrations as watched_half_count' => fn ($query) => $query->whereNotNull('reached_half_at'),
                'registrations as watched_end_count' => fn ($query) => $query->whereNotNull('watched_to_end_at'),
            ])
            ->orderBy('starts_at')
            ->paginate(15);

        return LiveShowResource::collection($liveShows);
    }

    public function store(StoreLiveShowRequest $request, RestreamService $restream, DailyService $daily)
    {
        abort_unless(
            $request->user()->team_id === (int) $request->input('team_id')
                || $request->user()->teams()->whereKey((int) $request->input('team_id'))->exists(),
            403,
        );

        $validated = $request->validated();
        $validated['settings'] = $this->normalizeSettings($validated['settings'] ?? []);
        $liveShow = LiveShow::query()->create($validated);
        RefreshKnowledgeEmbeddingsJob::dispatch('live_show', (int) $liveShow->id);

        $this->syncFeaturedProducts($request, $liveShow);

        // Auto-provision a live stream when the cast is created in go-live mode.
        $sourceType = (string) data_get($validated, 'settings.source_type', '');
        if ($sourceType === 'restream') {
            $liveShow->loadMissing('team');
            if ($liveShow->team !== null && $restream->enabled($liveShow->team)) {
                $mode = (string) data_get($validated, 'settings.restream.mode', 'go_live');
                $pullSource = (string) data_get($validated, 'settings.video_url', '');

                try {
                    $this->doCreateRestreamStream($liveShow, $restream, $mode, $pullSource);
                } catch (\Throwable $exception) {
                    Log::warning('Auto restream provisioning failed', [
                        'live_show_id' => $liveShow->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if ($sourceType === 'daily' && $daily->ready()) {
            try {
                $this->doCreateDailyRoom($liveShow, $daily);
            } catch (\Throwable $exception) {
                Log::warning('Auto Daily room provisioning failed', [
                    'live_show_id' => $liveShow->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return new LiveShowResource($liveShow->fresh(['featuredProducts', 'video', 'team'])->loadCount($this->liveShowCounts()));
    }

    public function show(LiveShow $liveShow)
    {
        $this->authorize('view', $liveShow);

        return new LiveShowResource($liveShow->load(['featuredProducts', 'video', 'team'])->loadCount($this->liveShowCounts()));
    }

    public function update(Request $request, LiveShow $liveShow)
    {
        $this->authorize('update', $liveShow);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:scheduled,live,ended,cancelled'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_premiere' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.host_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.video_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.source_type' => ['sometimes', 'nullable', 'in:ai,upload,url,restream,daily'],
            'settings.registration_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.registration_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.room_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.chat_enabled' => ['sometimes', 'boolean'],
            'settings.ai_assistant_enabled' => ['sometimes', 'boolean'],
            'settings.knowledge_base_text' => ['sometimes', 'nullable', 'string'],
            'settings.knowledge_sources' => ['sometimes', 'array', 'max:3'],
            'settings.knowledge_sources.*.title' => ['required_with:settings.knowledge_sources', 'string', 'max:255'],
            'settings.knowledge_sources.*.content' => ['required_with:settings.knowledge_sources', 'string'],
            'settings.restream' => ['sometimes', 'array'],
            'settings.restream.mode' => ['sometimes', 'nullable', 'in:go_live,pull_video'],
            'settings.restream.stream_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'settings.restream.stream_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.restream.ingest_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.restream.player_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.restream.srt_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.restream.multistream_targets' => ['sometimes', 'array', 'max:20'],
            'settings.restream.multistream_targets.*.name' => ['nullable', 'string', 'max:120'],
            'settings.restream.multistream_targets.*.url' => ['required_with:settings.restream.multistream_targets', 'string', 'max:2048'],
            'settings.restream.multistream_targets.*.profile' => ['nullable', 'string', 'max:40'],
            'settings.restream.multistream_targets.*.video_only' => ['nullable', 'boolean'],
            'featured_product_ids' => ['sometimes', 'array'],
            'featured_product_ids.*' => ['integer', 'exists:products,id'],
            'featured_products' => ['sometimes', 'array'],
            'featured_products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'featured_products.*.starts_at_ms' => ['nullable', 'integer', 'min:0'],
            'featured_products.*.ends_at_ms' => ['nullable', 'integer', 'min:0'],
            'featured_products.*.appearance' => ['nullable', 'string', 'in:pin,in_chat,popup'],
            'featured_products.*.cta_url' => ['nullable', 'string', 'max:2048'],
            'featured_products.*.pin_order' => ['nullable', 'integer', 'min:0'],
            'settings.video_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'],
        ]);

        $payload = collect($validated)->except(['featured_product_ids', 'featured_products'])->all();
        if (array_key_exists('settings', $payload)) {
            $payload['settings'] = $this->normalizeSettings(
                array_merge($liveShow->settings ?? [], Arr::get($payload, 'settings', [])),
            );
        }

        $liveShow->update($payload);
        if (array_key_exists('settings', $payload)) {
            RefreshKnowledgeEmbeddingsJob::dispatch('live_show', (int) $liveShow->id);
        }

        $this->syncFeaturedProducts($request, $liveShow);

        return new LiveShowResource($liveShow->fresh(['featuredProducts', 'video', 'team'])->loadCount($this->liveShowCounts()));
    }

    public function destroy(LiveShow $liveShow)
    {
        $this->authorize('delete', $liveShow);
        $liveShow->delete();

        return response()->noContent();
    }

    public function attendees(Request $request, LiveShow $liveShow)
    {
        $this->authorize('view', $liveShow);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        $attendees = LiveShowRegistration::query()
            ->where('live_show_id', $liveShow->id)
            ->select([
                'id',
                'full_name',
                'email',
                'registered_at',
                'last_joined_at',
                'join_count',
                'max_watch_ms',
                'reached_half_at',
                'watched_to_end_at',
            ])
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($attendees);
    }

    public function notifyAttendees(LiveShow $liveShow, WebinarAttendeeService $attendeeService)
    {
        $this->authorize('update', $liveShow);

        return response()->json([
            'data' => $attendeeService->notifyAll($liveShow),
            'message' => 'Attendee emails have been queued.',
        ]);
    }

    public function importAttendees(Request $request, LiveShow $liveShow, WebinarAttendeeService $attendeeService)
    {
        $this->authorize('update', $liveShow);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        return response()->json([
            'data' => $attendeeService->import($liveShow, $validated['file']),
            'message' => 'Attendees imported. Registration emails are being sent in the background.',
        ]);
    }

    public function conversations(Request $request, LiveShow $liveShow)
    {
        $this->authorize('view', $liveShow);

        $registrations = LiveShowRegistration::query()
            ->where('live_show_id', $liveShow->id)
            ->orderByDesc('last_joined_at')
            ->orderByDesc('registered_at')
            ->get();

        $conversations = $registrations->map(function (LiveShowRegistration $registration) use ($liveShow): array {
            $lastMessage = LiveShowMessage::query()
                ->where('live_show_id', $liveShow->id)
                ->where('live_show_registration_id', $registration->id)
                ->orderByDesc('id')
                ->first();

            $messagesCount = LiveShowMessage::query()
                ->where('live_show_id', $liveShow->id)
                ->where('live_show_registration_id', $registration->id)
                ->count();

            return [
                'registration_id' => $registration->id,
                'full_name' => $registration->full_name,
                'email' => $registration->email,
                'last_message' => $lastMessage?->message,
                'last_message_at' => $lastMessage?->created_at ?? $registration->last_joined_at ?? $registration->registered_at,
                'messages_count' => $messagesCount,
            ];
        })->values();

        return response()->json(['data' => $conversations]);
    }

    public function messages(Request $request, LiveShow $liveShow)
    {
        $this->authorize('view', $liveShow);

        $validated = $request->validate([
            'registration_id' => ['nullable', 'integer'],
        ]);

        $messages = LiveShowMessage::query()
            ->where('live_show_id', $liveShow->id)
            ->when(
                array_key_exists('registration_id', $validated),
                fn ($query) => $query->where(
                    'live_show_registration_id',
                    $validated['registration_id'],
                ),
            )
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (LiveShowMessage $message): array => [
                'id' => $message->id,
                'live_show_registration_id' => $message->live_show_registration_id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'message' => $message->message,
                'is_pinned' => (bool) $message->is_pinned,
                'created_at' => $message->created_at,
            ]);

        return response()->json(['data' => $messages]);
    }

    public function postHostMessage(Request $request, LiveShow $liveShow)
    {
        $this->authorize('update', $liveShow);

        $validated = $request->validate([
            'sender_name' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'registration_id' => ['nullable', 'integer', 'exists:live_show_registrations,id'],
        ]);

        if (! empty($validated['registration_id'])) {
            abort_unless(
                LiveShowRegistration::query()
                    ->whereKey((int) $validated['registration_id'])
                    ->where('live_show_id', $liveShow->id)
                    ->exists(),
                422,
                'Invalid registration for this webinar.',
            );
        }

        $message = LiveShowMessage::query()->create([
            'live_show_id' => $liveShow->id,
            'live_show_registration_id' => $validated['registration_id'] ?? null,
            'sender_type' => 'host',
            'sender_name' => trim((string) ($validated['sender_name'] ?? data_get($liveShow->settings, 'host_name', 'Host'))),
            'message' => trim((string) $validated['message']),
        ]);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'live_show_registration_id' => $message->live_show_registration_id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'message' => $message->message,
                'is_pinned' => (bool) $message->is_pinned,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }

    public function updateMessage(Request $request, LiveShow $liveShow, LiveShowMessage $message)
    {
        $this->authorize('update', $liveShow);
        abort_unless($message->live_show_id === $liveShow->id, 404);

        $validated = $request->validate([
            'is_pinned' => ['sometimes', 'boolean'],
            'message' => ['sometimes', 'string', 'max:2000'],
            'sender_type' => ['sometimes', Rule::in(['host', 'attendee', 'ai', 'system'])],
        ]);

        $message->update($validated);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'message' => $message->message,
                'is_pinned' => (bool) $message->is_pinned,
                'created_at' => $message->created_at,
            ],
        ]);
    }

    public function destroyMessage(LiveShow $liveShow, LiveShowMessage $message)
    {
        $this->authorize('update', $liveShow);
        abort_unless($message->live_show_id === $liveShow->id, 404);

        $message->delete();

        return response()->noContent();
    }

    public function createRestreamStream(
        Request $request,
        LiveShow $liveShow,
        RestreamService $restream,
    ) {
        $this->authorize('update', $liveShow);
        $liveShow->loadMissing('team');
        $team = $liveShow->team;
        abort_if($team === null, 422, 'Live show team is missing.');
        abort_unless($restream->enabled($team), 422, 'Live streaming credentials are missing in environment.');
        abort_unless($restream->ready($team), 422, 'Live streaming is not configured yet.');

        $validated = $request->validate([
            'mode' => ['nullable', Rule::in(['go_live', 'pull_video'])],
            'pull_source' => ['nullable', 'url', 'max:2048'],
            'record' => ['nullable', 'boolean'],
            'multistream_targets' => ['nullable', 'array', 'max:20'],
            'multistream_targets.*.name' => ['nullable', 'string', 'max:120'],
            'multistream_targets.*.url' => ['required_with:multistream_targets', 'string', 'max:2048', 'regex:/^(srt|rtmps?):\/\//i'],
            'multistream_targets.*.profile' => ['nullable', 'string', 'max:40'],
            'multistream_targets.*.video_only' => ['nullable', 'boolean'],
        ]);

        $mode = (string) ($validated['mode'] ?? 'go_live');
        $pullSource = trim((string) ($validated['pull_source'] ?? ''));

        if ($mode === 'pull_video' && $pullSource === '') {
            return response()->json([
                'message' => 'A pull source URL is required for prerecorded stream mode.',
            ], 422);
        }

        try {
            $this->doCreateRestreamStream($liveShow, $restream, $mode, $pullSource, (bool) ($validated['record'] ?? true), $validated['multistream_targets'] ?? []);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $liveShow = $liveShow->fresh(['featuredProducts', 'video', 'team'])->loadCount($this->liveShowCounts());
        $streamKey = trim((string) data_get($liveShow->settings, 'restream.stream_key', ''));

        abort_if($streamKey === '', 422, 'Streaming provider did not return stream credentials. Check your API key and try again.');

        return response()->json([
            'message' => 'Live stream created.',
            'data' => new LiveShowResource($liveShow),
        ]);
    }

    public function restreamStreamStatus(
        LiveShow $liveShow,
        RestreamService $restream,
        LiveBroadcastSessionService $sessions,
    ) {
        $this->authorize('update', $liveShow);
        $liveShow->loadMissing('team');
        $team = $liveShow->team;
        abort_if($team === null, 422, 'Live show team is missing.');
        abort_unless($restream->enabled($team), 422, 'Live streaming credentials are missing in environment.');
        abort_unless($restream->ready($team), 422, 'Live streaming is not configured yet.');

        $local = $sessions->statusForLiveShow($liveShow->id);

        if ($local['active']) {
            return response()->json([
                'data' => [
                    'is_active' => true,
                    'last_seen' => $local['last_seen'],
                    'source_segments' => $local['source_segments'],
                    'channels' => [],
                    'reachable' => true,
                ],
            ]);
        }

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $streamId = trim((string) data_get($settings, 'restream.stream_id', ''));
        abort_if($streamId === '', 422, 'No live stream has been provisioned yet.');

        try {
            $stream = $restream->getStream($team, $streamId);
            $channels = $restream->listChannels($team);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Could not reach streaming provider. Check your internet connection.',
                'data' => [
                    'is_active' => false,
                    'last_seen' => null,
                    'source_segments' => 0,
                    'channels' => [],
                    'reachable' => false,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'is_active' => (bool) data_get($stream, 'isActive', false),
                'last_seen' => data_get($stream, 'lastSeen'),
                'source_segments' => (int) data_get($stream, 'sourceSegments', 0),
                'channels' => $channels,
                'reachable' => true,
            ],
        ]);
    }

    public function startBroadcastSession(
        LiveShow $liveShow,
        LiveBroadcastIngestService $ingest,
        LiveBroadcastSessionService $sessions,
        RestreamService $restream,
    ) {
        $this->authorize('update', $liveShow);
        $rtmpUrl = $this->resolveLiveBroadcastRtmpUrl($liveShow, $restream);

        if (! $ingest->isAvailable()) {
            return response()->json([
                'message' => 'Broadcast encoder is not available on this server. Install FFmpeg and set LIVE_BROADCAST_FFMPEG_PATH if needed.',
            ], 503);
        }

        try {
            $session = $sessions->start($liveShow->id, $rtmpUrl, $ingest);
        } catch (\Throwable $exception) {
            Log::warning('Live broadcast session start failed', [
                'live_show_id' => $liveShow->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not start broadcasting. Try again in a moment.',
            ], 500);
        }

        return response()->json([
            'data' => [
                'session_id' => $session['session_id'],
            ],
        ]);
    }

    public function broadcastChunk(
        Request $request,
        LiveShow $liveShow,
        LiveBroadcastSessionService $sessions,
        RestreamService $restream,
    ) {
        $this->authorize('update', $liveShow);
        $this->resolveLiveBroadcastRtmpUrl($liveShow, $restream);

        $sessionId = trim((string) $request->header('X-Broadcast-Session', ''));
        $chunkIndex = (int) $request->header('X-Broadcast-Chunk', -1);
        $format = strtolower(trim((string) $request->header('X-Broadcast-Format', 'webm')));

        abort_if($sessionId === '' || ! \Illuminate\Support\Str::isUuid($sessionId), 422, 'Broadcast session is missing.');
        abort_if($chunkIndex < 0, 422, 'Broadcast chunk index is missing.');

        $binary = $request->getContent();

        if ($binary === '' || $binary === false) {
            return response()->json(['message' => 'Empty broadcast chunk.'], 422);
        }

        try {
            $sessions->appendChunk(
                $sessionId,
                $liveShow->id,
                $chunkIndex,
                $binary,
                $format,
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['accepted' => true]]);
    }

    public function stopBroadcastSession(
        Request $request,
        LiveShow $liveShow,
        LiveBroadcastSessionService $sessions,
        RestreamService $restream,
    ) {
        $this->authorize('update', $liveShow);
        $this->resolveLiveBroadcastRtmpUrl($liveShow, $restream);

        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
        ]);

        try {
            $sessions->stop((string) $validated['session_id'], $liveShow->id);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['stopped' => true]]);
    }

    protected function resolveLiveBroadcastRtmpUrl(LiveShow $liveShow, RestreamService $restream): string
    {
        $liveShow->loadMissing('team');
        $team = $liveShow->team;
        abort_if($team === null, 422, 'Live show team is missing.');
        abort_unless($restream->enabled($team), 422, 'Live streaming is not configured.');
        abort_unless($restream->ready($team), 422, 'Live streaming is not ready yet.');

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $streamKey = trim((string) data_get($settings, 'restream.stream_key', ''));
        abort_if($streamKey === '', 422, 'No stream has been provisioned yet.');

        $ingestUrl = trim((string) config('services.restream.ingest_url', 'rtmp://live.restream.io/live'));

        return rtrim($ingestUrl, '/').'/'.$streamKey;
    }

    /**
     * Core stream provisioning — shared by store() auto-create and the manual refresh endpoint.
     *
     * @param  array<int, mixed>  $multistreamTargets
     */
    private function doCreateRestreamStream(
        LiveShow $liveShow,
        RestreamService $restream,
        string $mode = 'go_live',
        string $pullSource = '',
        bool $record = true,
        array $multistreamTargets = [],
    ): void {
        $team = $liveShow->team;

        if ($team === null) {
            throw new \RuntimeException('Live show team is missing.');
        }

        $streamPayload = [
            'name' => 'live-show-'.$liveShow->id.'-'.now()->format('Ymd-His'),
            'record' => $record,
        ];

        if ($mode === 'pull_video' && $pullSource !== '') {
            $streamPayload['pull'] = ['source' => $pullSource];
        }

        $targets = collect($multistreamTargets)
            ->filter(fn (mixed $t): bool => is_array($t) && ! empty($t['url']))
            ->map(fn (array $t): array => [
                'profile' => (string) ($t['profile'] ?? 'source'),
                'videoOnly' => (bool) ($t['video_only'] ?? false),
                'spec' => [
                    'name' => trim((string) ($t['name'] ?? '')),
                    'url' => trim((string) ($t['url'] ?? '')),
                ],
            ])
            ->values()
            ->all();

        if ($targets !== []) {
            $streamPayload['multistream'] = ['targets' => $targets];
        }

        $stream = $restream->createStream($team, $streamPayload);

        $streamId = (string) data_get($stream, 'id', '');
        $streamKey = (string) data_get($stream, 'streamKey', '');
        $ingestUrl = (string) data_get($stream, 'ingestUrl', 'rtmp://live.restream.io/live');
        $playerUrl = app(LiveBroadcastSessionService::class)->playbackUrlForLiveShow($liveShow->id);

        if ($streamId === '') {
            throw new \RuntimeException('Streaming provider did not return stream id.');
        }

        if ($streamKey === '') {
            throw new \RuntimeException('Streaming provider did not return a stream key.');
        }

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $settings['source_type'] = 'restream';
        if ($playerUrl !== '') {
            $settings['video_url'] = $playerUrl;
        }
        $settings['restream'] = array_filter([
            'mode' => $mode,
            'stream_id' => $streamId,
            'stream_key' => $streamKey !== '' ? $streamKey : null,
            'ingest_url' => $ingestUrl,
            'player_url' => $playerUrl !== '' ? $playerUrl : null,
            'ingest_id' => data_get($stream, 'ingestId'),
            'srt_url' => data_get($stream, 'srtUrl'),
            'channels' => data_get($stream, 'channels', []),
            'multistream_targets' => collect($targets)->map(fn (array $t): array => [
                'name' => (string) data_get($t, 'spec.name', ''),
                'url' => (string) data_get($t, 'spec.url', ''),
                'profile' => (string) ($t['profile'] ?? 'source'),
                'video_only' => (bool) ($t['videoOnly'] ?? false),
            ])->all(),
        ], fn (mixed $v): bool => $v !== null);

        $liveShow->update(['settings' => $this->normalizeSettings($settings)]);
        $liveShow->refresh();
    }

    private function doCreateDailyRoom(LiveShow $liveShow, DailyService $daily): void
    {
        $room = $daily->createRoomForLiveShow($liveShow);
        $roomName = trim((string) ($room['name'] ?? $daily->roomNameForLiveShow($liveShow)));
        $roomUrl = trim((string) ($room['url'] ?? ''));

        if ($roomName === '' || $roomUrl === '') {
            throw new \RuntimeException('Daily did not return a room name or URL.');
        }

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $settings['source_type'] = 'daily';
        $settings['daily'] = array_filter([
            'room_name' => $roomName,
            'room_url' => $roomUrl,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $liveShow->update(['settings' => $this->normalizeSettings($settings)]);
        $liveShow->refresh();
    }

    public function dailyHostToken(Request $request, LiveShow $liveShow, DailyService $daily)
    {
        $this->authorize('update', $liveShow);

        abort_unless($daily->ready(), 422, 'Daily live streaming is not configured. Add DAILY_API_KEY to your environment.');

        $roomName = trim((string) data_get($liveShow->settings, 'daily.room_name', ''));

        if ($roomName === '') {
            $this->doCreateDailyRoom($liveShow, $daily);
            $liveShow->refresh();
        }

        $userName = trim((string) $request->input('user_name', $request->user()?->name ?? 'Host'));

        return response()->json([
            'token' => $daily->createHostToken($liveShow, $userName),
            'room_url' => data_get($liveShow->settings, 'daily.room_url'),
            'room_name' => data_get($liveShow->settings, 'daily.room_name'),
        ]);
    }

    public function addRestreamTarget(
        Request $request,
        LiveShow $liveShow,
        RestreamService $restream,
    ) {
        $this->authorize('update', $liveShow);
        $liveShow->loadMissing('team');
        $team = $liveShow->team;
        abort_if($team === null, 422, 'Live show team is missing.');
        abort_unless($restream->enabled($team), 422, 'Live streaming credentials are missing in environment.');
        abort_unless($restream->ready($team), 422, 'Live streaming is not configured yet.');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2048', 'regex:/^(srt|rtmps?):\/\//i'],
            'profile' => ['nullable', 'string', 'max:40'],
            'video_only' => ['nullable', 'boolean'],
        ]);

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $streamId = (string) data_get($settings, 'restream.stream_id', '');
        abort_if($streamId === '', 422, 'Create a live stream first.');

        $target = [
            'profile' => (string) ($validated['profile'] ?? 'source'),
            'videoOnly' => (bool) ($validated['video_only'] ?? false),
            'spec' => [
                'name' => trim((string) ($validated['name'] ?? '')),
                'url' => trim((string) $validated['url']),
            ],
        ];

        $restream->addMultistreamTarget($team, $streamId, $target);
        $channels = $restream->listChannels($team);

        $targets = collect((array) data_get($settings, 'restream.multistream_targets', []))
            ->push([
                'name' => (string) data_get($target, 'spec.name', ''),
                'url' => (string) data_get($target, 'spec.url', ''),
                'profile' => (string) ($target['profile'] ?? 'source'),
                'video_only' => (bool) ($target['videoOnly'] ?? false),
            ])
            ->values()
            ->all();

        data_set($settings, 'restream.multistream_targets', $targets);
        data_set($settings, 'restream.channels', $channels);

        $liveShow->update([
            'settings' => $this->normalizeSettings($settings),
        ]);

        return response()->json([
            'message' => 'Multistream target added.',
            'data' => new LiveShowResource($liveShow->fresh(['featuredProducts', 'video', 'team'])->loadCount($this->liveShowCounts())),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    /**
     * @return array<int|string, mixed>
     */
    protected function liveShowCounts(): array
    {
        return [
            'registrations',
            'messages',
            'registrations as conversations_count' => fn ($query) => $query->whereHas('messages'),
            'registrations as watched_half_count' => fn ($query) => $query->whereNotNull('reached_half_at'),
            'registrations as watched_end_count' => fn ($query) => $query->whereNotNull('watched_to_end_at'),
        ];
    }

    protected function normalizeSettings(array $settings): array
    {
        $rawSources = $settings['knowledge_sources'] ?? [];
        $sources = collect(is_array($rawSources) ? $rawSources : [])
            ->take(3)
            ->filter(fn (mixed $s): bool => is_array($s) && ! empty($s['title']) && ! empty($s['content']))
            ->map(fn (array $s): array => [
                'title' => trim((string) $s['title']),
                'content' => trim((string) $s['content']),
            ])
            ->values()
            ->all();

        $rawRestreamTargets = data_get($settings, 'restream.multistream_targets', []);
        $restreamTargets = collect(is_array($rawRestreamTargets) ? $rawRestreamTargets : [])
            ->filter(fn (mixed $target): bool => is_array($target) && ! empty($target['url']))
            ->map(fn (array $target): array => [
                'name' => trim((string) ($target['name'] ?? '')),
                'url' => trim((string) ($target['url'] ?? '')),
                'profile' => trim((string) ($target['profile'] ?? 'source')) ?: 'source',
                'video_only' => (bool) ($target['video_only'] ?? false),
            ])
            ->values()
            ->all();

        $daily = null;
        $rawDaily = $settings['daily'] ?? null;
        if (is_array($rawDaily)) {
            $daily = array_filter([
                'room_name' => isset($rawDaily['room_name']) ? trim((string) $rawDaily['room_name']) : null,
                'room_url' => isset($rawDaily['room_url']) ? trim((string) $rawDaily['room_url']) : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $restream = null;
        $rawRestream = $settings['restream'] ?? null;
        if (is_array($rawRestream)) {
            $restream = array_filter([
                'mode' => in_array((string) ($rawRestream['mode'] ?? ''), ['go_live', 'pull_video'], true)
                    ? (string) $rawRestream['mode']
                    : 'go_live',
                'stream_id' => isset($rawRestream['stream_id']) ? trim((string) $rawRestream['stream_id']) : null,
                'stream_key' => isset($rawRestream['stream_key']) ? trim((string) $rawRestream['stream_key']) : null,
                'ingest_url' => isset($rawRestream['ingest_url']) ? trim((string) $rawRestream['ingest_url']) : null,
                'player_url' => isset($rawRestream['player_url']) ? trim((string) $rawRestream['player_url']) : null,
                'srt_url' => isset($rawRestream['srt_url']) ? trim((string) $rawRestream['srt_url']) : null,
                'ingest_id' => isset($rawRestream['ingest_id']) ? (int) $rawRestream['ingest_id'] : null,
                'channels' => is_array($rawRestream['channels'] ?? null) ? $rawRestream['channels'] : null,
                'multistream_targets' => $restreamTargets !== [] ? $restreamTargets : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $sourceType = isset($settings['source_type']) ? trim((string) $settings['source_type']) : 'upload';

        return array_filter([
            'host_name' => isset($settings['host_name']) ? trim((string) $settings['host_name']) : null,
            'thumbnail_url' => isset($settings['thumbnail_url']) ? trim((string) $settings['thumbnail_url']) : null,
            'video_url' => isset($settings['video_url']) ? trim((string) $settings['video_url']) : null,
            'source_type' => $sourceType,
            'registration_title' => isset($settings['registration_title']) ? trim((string) $settings['registration_title']) : null,
            'registration_description' => isset($settings['registration_description']) ? trim((string) $settings['registration_description']) : null,
            'room_title' => isset($settings['room_title']) ? trim((string) $settings['room_title']) : null,
            'chat_enabled' => (bool) ($settings['chat_enabled'] ?? true),
            'ai_assistant_enabled' => (bool) ($settings['ai_assistant_enabled'] ?? false),
            'knowledge_base_text' => isset($settings['knowledge_base_text']) ? trim((string) $settings['knowledge_base_text']) : null,
            'knowledge_sources' => ! empty($sources) ? $sources : null,
            'restream' => $restream,
            'daily' => $daily,
            'views_count' => (int) ($settings['views_count'] ?? 0),
            'video_duration_seconds' => isset($settings['video_duration_seconds'])
                ? max(1, (int) $settings['video_duration_seconds'])
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function syncFeaturedProducts(Request $request, LiveShow $liveShow): void
    {
        $offerService = app(WebinarOfferService::class);

        if ($request->has('featured_products')) {
            $offerService->syncOffers($liveShow, (array) $request->input('featured_products', []));

            return;
        }

        if ($request->has('featured_product_ids')) {
            $syncData = collect($request->input('featured_product_ids'))
                ->values()
                ->mapWithKeys(fn ($productId, $index): array => [
                    (int) $productId => [
                        'starts_at_ms' => 0,
                        'ends_at_ms' => null,
                        'pin_order' => $index,
                        'appearance' => 'popup',
                        'cta_url' => null,
                    ],
                ])
                ->all();
            $liveShow->featuredProducts()->sync($syncData);
        }
    }
}
