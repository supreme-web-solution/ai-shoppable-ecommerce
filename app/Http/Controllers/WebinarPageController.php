<?php

namespace App\Http\Controllers;

use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
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

        $registrationId = $request->integer('registration');

        if ($registrationId > 0) {
            $registrationValid = LiveShowRegistration::query()
                ->whereKey($registrationId)
                ->where('live_show_id', $liveShow->id)
                ->exists();

            if (! $registrationValid) {
                return redirect()
                    ->route('webinars.register', $liveShow)
                    ->withErrors([
                        'registration' => 'Your session expired or is invalid. Please register again to join the room.',
                    ]);
            }
        }

        return inertia('webinars/Room', [
            'webinarId' => $liveShow->id,
            'registrationId' => $registrationId,
            'needsRegistration' => $registrationId <= 0,
        ]);
    }
}
