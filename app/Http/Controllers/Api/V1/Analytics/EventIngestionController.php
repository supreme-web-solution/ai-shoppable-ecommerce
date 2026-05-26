<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TrackAnalyticsEventRequest;
use App\Services\Analytics\EventIngestionService;
use App\Support\TeamApiAuthorizer;

class EventIngestionController extends Controller
{
    public function store(
        TrackAnalyticsEventRequest $request,
        EventIngestionService $eventIngestionService,
        TeamApiAuthorizer $authorizer,
    )
    {
        $authorizer->assertAnalyticsIngestAccess($request, (int) $request->input('team_id'));

        $event = $eventIngestionService->ingest([
            ...$request->validated(),
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'ok' => true,
            'event_id' => $event->id,
        ], 201);
    }
}
