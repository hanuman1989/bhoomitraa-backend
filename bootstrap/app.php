<?php

use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\SetLocale;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.active' => EnsureActiveAdmin::class,
            'user.active' => EnsureActiveUser::class,
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(static fn (): ?string => null);
        $middleware->api(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception): bool => $request->is('api', 'api/*') || $request->expectsJson());

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->is('api', 'api/*')) {
                return $response;
            }

            $httpStatus = $response->getStatusCode();
            $message = match ($httpStatus) {
                401 => 'Unauthenticated.',
                403 => 'Forbidden.',
                404 => 'Resource not found.',
                405 => 'Method not allowed.',
                409 => 'Conflict.',
                422 => 'Validation failed.',
                429 => 'Too many requests. Please try again later.',
                default => $httpStatus >= 500 ? 'An unexpected error occurred.' : (Response::$statusTexts[$httpStatus] ?? 'Request failed.'),
            };

            if ($httpStatus === 403 && $exception instanceof HttpExceptionInterface && in_array($exception->getMessage(), ['Admin account is inactive.', 'User account is inactive.'], true)) {
                $message = $exception->getMessage();
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                $httpStatus = 409;
                $message = 'A record with these details already exists.';
            } elseif ($exception instanceof QueryException || $exception instanceof PDOException) {
                $message = 'Unable to complete the database operation. Please try again later.';
            }

            $payload = [
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => $message,
            ];

            if ($exception instanceof ValidationException) {
                $payload['errors'] = $exception->errors();
            }

            $jsonResponse = response()->json($payload, $httpStatus);
            $headers = $response->headers->all();
            unset($headers['content-type'], $headers['content-length']);
            $jsonResponse->headers->add($headers);

            return $jsonResponse;
        });
    })->create();
