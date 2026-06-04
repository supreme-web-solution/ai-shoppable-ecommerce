<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLiveShowRequest;
use App\Http\Resources\Api\V1\LiveShowResource;
use App\Jobs\RefreshKnowledgeEmbeddingsJob;
use App\Models\LiveShow;
use App\Models\LiveShowMessage;
use App\Models\LiveShowRegistration;
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

    public function store(StoreLiveShowRequest $request)
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
            'settings.source_type' => ['sometimes', 'nullable', 'in:ai,upload,url'],
            'settings.registration_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.registration_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.room_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.chat_enabled' => ['sometimes', 'boolean'],
            'settings.ai_assistant_enabled' => ['sometimes', 'boolean'],
            'settings.knowledge_base_text' => ['sometimes', 'nullable', 'string'],
            'settings.knowledge_sources' => ['sometimes', 'array', 'max:3'],
            'settings.knowledge_sources.*.title' => ['required_with:settings.knowledge_sources', 'string', 'max:255'],
            'settings.knowledge_sources.*.content' => ['required_with:settings.knowledge_sources', 'string'],
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

        return array_filter([
            'host_name' => isset($settings['host_name']) ? trim((string) $settings['host_name']) : null,
            'thumbnail_url' => isset($settings['thumbnail_url']) ? trim((string) $settings['thumbnail_url']) : null,
            'video_url' => isset($settings['video_url']) ? trim((string) $settings['video_url']) : null,
            'source_type' => isset($settings['source_type']) ? trim((string) $settings['source_type']) : 'upload',
            'registration_title' => isset($settings['registration_title']) ? trim((string) $settings['registration_title']) : null,
            'registration_description' => isset($settings['registration_description']) ? trim((string) $settings['registration_description']) : null,
            'room_title' => isset($settings['room_title']) ? trim((string) $settings['room_title']) : null,
            'chat_enabled' => (bool) ($settings['chat_enabled'] ?? true),
            'ai_assistant_enabled' => (bool) ($settings['ai_assistant_enabled'] ?? false),
            'knowledge_base_text' => isset($settings['knowledge_base_text']) ? trim((string) $settings['knowledge_base_text']) : null,
            'knowledge_sources' => ! empty($sources) ? $sources : null,
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
