<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash, VerifyEmailAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $id, $hash);

        return response()->json([
            'message' => 'Correo verificado correctamente.',
            'email_verified' => true,
        ]);
    }
}
