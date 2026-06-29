<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendEmailVerificationNotificationAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SendEmailVerificationNotificationController extends Controller
{
    public function __invoke(Request $request, SendEmailVerificationNotificationAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user);

        return response()->json([
            'message' => 'Si tu correo aún no está verificado, enviaremos un enlace de verificación.',
        ]);
    }
}
