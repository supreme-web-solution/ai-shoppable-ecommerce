<?php

namespace App\Http\Responses;

use Illuminate\Http\Response;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        $session = $request->session();
        $session->regenerate();
        $session->regenerateToken();
        $session->save();

        if ($request->wantsJson()) {
            return response()->json('', Response::HTTP_NO_CONTENT);
        }

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }
}
