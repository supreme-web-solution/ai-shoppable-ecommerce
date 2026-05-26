<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        $session = $request->session();
        $session->regenerate();
        $session->regenerateToken();
        $session->save();

        if ($request->wantsJson()) {
            return response()->json('', Response::HTTP_CREATED);
        }

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }
}
