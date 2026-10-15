<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check((string) $request->string('password'), $user->password)) {
            AuditLogger::log('auth.failed', null, ['email' => $request->string('email')], 'guest', $request->ip());

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'))) {
            throw ValidationException::withMessages([
                'email' => ['This account is not allowed to access the control plane.'],
            ]);
        }

        $token = $user->createToken((string) $request->string('device_name', 'admin-api'));

        AuditLogger::log('auth.token_issued', $user, ['device' => $request->string('device_name')], $user->name, $request->ip());

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'abilities' => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        AuditLogger::log('auth.token_revoked', $user, [], $user->name, $request->ip());

        return response()->json(['ok' => true]);
    }
}
