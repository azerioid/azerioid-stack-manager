<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** Caddy forward_auth target: panel session present → 200, otherwise 401. Loopback only. */
final class PanelAuthCheckController
{
    public function __invoke(Request $request): Response
    {
        if (! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            abort(403);
        }

        if (! Auth::check()) {
            abort(401);
        }

        return response('', 200);
    }
}
