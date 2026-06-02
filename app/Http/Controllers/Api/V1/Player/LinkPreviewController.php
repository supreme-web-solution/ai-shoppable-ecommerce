<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Services\LinkPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkPreviewController extends Controller
{
    public function show(Request $request, LinkPreviewService $linkPreviewService): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $url = trim((string) $validated['url']);

        if (! preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        $preview = $linkPreviewService->resolve($url);

        if ($preview === null) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => $preview]);
    }
}
