<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionUserResource;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * The signed-in user and the companies they can enter.
     */
    public function show(Request $request): SessionUserResource
    {
        return new SessionUserResource($request->user());
    }
}
