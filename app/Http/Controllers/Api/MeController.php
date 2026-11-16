<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles:id,slug,name');

        return response()->json(['success' => true, 'data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'designation' => $user->designation,
            'roles' => $user->roles->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name]),
            'permissions' => array_keys($user->permissionSlugs()),
            'facilities' => $user->accessibleFacilities()->get(['id', 'name']),
        ]]);
    }

    public function permissions(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => array_keys($request->user()->permissionSlugs())]);
    }
}
