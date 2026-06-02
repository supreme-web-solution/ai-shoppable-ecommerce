<?php

namespace App\Http\Controllers;

use App\Models\Embed;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopPageController extends Controller
{
    public function show(Request $request, string $slug): View
    {
        $embed = Embed::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return view('shop', [
            'embed' => $embed,
        ]);
    }
}
