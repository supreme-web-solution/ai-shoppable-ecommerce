<?php

namespace App\Http\Controllers;

use App\Models\LiveShow;
use Illuminate\Http\Request;

class WebinarPageController extends Controller
{
    public function register(Request $request, LiveShow $liveShow)
    {
        abort_if($liveShow->status === 'cancelled', 404);

        return inertia('webinars/Register', [
            'webinarId' => $liveShow->id,
        ]);
    }

    public function room(Request $request, LiveShow $liveShow)
    {
        abort_if($liveShow->status === 'cancelled', 404);

        return inertia('webinars/Room', [
            'webinarId' => $liveShow->id,
            'registrationId' => $request->integer('registration'),
        ]);
    }
}
