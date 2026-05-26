<?php

namespace App\Http\Controllers;

use App\Models\Embed;
use Illuminate\View\View;

class EmbedPageController extends Controller
{
    public function show(string $slug): View
    {
        $embed = Embed::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return view('embed', [
            'embed' => $embed,
        ]);
    }
}
