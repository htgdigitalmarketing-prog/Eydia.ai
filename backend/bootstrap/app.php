<?php

use App\Modules\WhatsApp\Exceptions\WhatsAppApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenant::class,
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'plan' => \App\Http\Middleware\EnsureActivePlan::class,
            'feature' => \App\Http\Middleware\EnsureFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every API error speaks the same envelope: { success:false, message, errors? }
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null; // let the default handler deal with non-API requests
            }

            // WhatsApp errors: show the user-safe message plus Meta's own detail
            // (and code) so unmapped errors are actionable, not "please try again".
            if ($e instanceof WhatsAppApiException) {
                $msg = $e->userMessage;
                $metaMsg = data_get($e->raw, 'error.message');
                if ($metaMsg) {
                    $msg .= ' — Meta: ' . $metaMsg;
                } elseif ($e->metaCode) {
                    $msg .= ' (Meta #' . $e->metaCode . ')';
                }
                return response()->json(['success' => false, 'message' => $msg], $e->httpStatus);
            }

            [$status, $message] = match (true) {
                $e instanceof ValidationException => [422, 'The given data was invalid.'],
                $e instanceof AuthenticationException => [401, 'Unauthenticated.'],
                $e instanceof AuthorizationException => [403, 'This action is unauthorized.'],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'Resource not found.'],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage() ?: 'Request failed.',
                ],
                default => [500, 'Server error.'],
            };

            $payload = ['success' => false, 'message' => $message];

            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            // Never leak internals in production; surface detail only in debug.
            if ($status === 500 && config('app.debug')) {
                $payload['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];
            }

            return response()->json($payload, $status);
        });
    })
    ->create();
