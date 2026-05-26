<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LiveShowResource;
use App\Models\LiveShow;
use App\Models\LiveShowMessage;
use App\Models\LiveShowRegistration;
use App\Services\Ai\WebinarAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebinarController extends Controller
{
    public function show(LiveShow $liveShow): JsonResponse
    {
        abort_if($liveShow->status === 'cancelled', 404);

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        $settings['views_count'] = (int) ($settings['views_count'] ?? 0) + 1;
        $liveShow->forceFill(['settings' => $settings])->save();

        $liveShow->load(['featuredProducts', 'video'])->loadCount(['registrations', 'messages']);

        return response()->json([
            'data' => (new LiveShowResource($liveShow))->resolve(),
        ]);
    }

    public function register(Request $request, LiveShow $liveShow): JsonResponse
    {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $registration = LiveShowRegistration::query()
            ->where('live_show_id', $liveShow->id)
            ->where('email', $validated['email'])
            ->first();

        if (! $registration) {
            $registration = LiveShowRegistration::query()->create([
                'live_show_id' => $liveShow->id,
                'full_name' => trim($validated['full_name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'registered_at' => now(),
                'last_joined_at' => now(),
                'join_count' => 1,
            ]);
        } else {
            $registration->update([
                'full_name' => trim($validated['full_name']),
                'last_joined_at' => now(),
                'join_count' => (int) $registration->join_count + 1,
            ]);
        }

        return response()->json([
            'data' => [
                'registration_id' => $registration->id,
                'room_url' => url("/webinars/{$liveShow->id}/room?registration={$registration->id}"),
            ],
        ]);
    }

    public function messages(Request $request, LiveShow $liveShow): JsonResponse
    {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $query = LiveShowMessage::query()
            ->where('live_show_id', $liveShow->id)
            ->when(
                isset($validated['after_id']),
                fn ($builder) => $builder->where('id', '>', (int) $validated['after_id']),
            )
            ->orderBy('id')
            ->limit($limit);

        return response()->json([
            'data' => $query->get()->map(fn (LiveShowMessage $message): array => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'message' => $message->message,
                'is_pinned' => (bool) $message->is_pinned,
                'created_at' => $message->created_at,
            ]),
        ]);
    }

    public function sendMessage(
        Request $request,
        LiveShow $liveShow,
        WebinarAssistantService $assistantService,
    ): JsonResponse {
        abort_if($liveShow->status === 'cancelled', 404);

        $validated = $request->validate([
            'registration_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $registration = LiveShowRegistration::query()
            ->whereKey((int) $validated['registration_id'])
            ->where('live_show_id', $liveShow->id)
            ->firstOrFail();

        $message = LiveShowMessage::query()->create([
            'live_show_id' => $liveShow->id,
            'live_show_registration_id' => $registration->id,
            'sender_type' => 'attendee',
            'sender_name' => $registration->full_name,
            'message' => trim($validated['message']),
        ]);

        $created = [
            [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_name,
                'message' => $message->message,
                'is_pinned' => (bool) $message->is_pinned,
                'created_at' => $message->created_at,
            ],
        ];

        $settings = is_array($liveShow->settings) ? $liveShow->settings : [];
        if ((bool) data_get($settings, 'ai_assistant_enabled', false)) {
            $replyText = $assistantService->buildReply($liveShow, (string) $message->message);
            $aiMessage = LiveShowMessage::query()->create([
                'live_show_id' => $liveShow->id,
                'live_show_registration_id' => $registration->id,
                'sender_type' => 'ai',
                'sender_name' => 'AI Assistant',
                'message' => $replyText,
            ]);

            $created[] = [
                'id' => $aiMessage->id,
                'sender_type' => $aiMessage->sender_type,
                'sender_name' => $aiMessage->sender_name,
                'message' => $aiMessage->message,
                'is_pinned' => (bool) $aiMessage->is_pinned,
                'created_at' => $aiMessage->created_at,
            ];
        }

        return response()->json(['data' => $created], 201);
    }
}
