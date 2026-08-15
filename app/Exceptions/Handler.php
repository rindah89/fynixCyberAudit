<?php

namespace App\Exceptions;

use App\Support\Observability\SentryTransport;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            app(SentryTransport::class)->capture($e);
        });

        // Return JSON responses for survey routes when Accept header is JSON
        $this->renderable(function (Throwable $e, Request $request) {
            if ($request->is('survey/*') && $request->expectsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                return response()->json([
                    'error' => $e->getMessage() ?: 'An error occurred',
                ], $status);
            }
        });
    }
}
