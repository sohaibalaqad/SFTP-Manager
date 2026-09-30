<?php

use App\Exceptions\SftpException;
use App\Services\SftpService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // File contents, paths and names must reach the server byte-for-byte.
        $fields = ['content', 'path', 'paths', 'paths.*', 'dirs', 'dirs.*', 'files', 'files.*', 'dest', 'list', 'name', 'password', 'private_key', 'passphrase'];
        $middleware->trimStrings(except: $fields);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $r) => $r->is('api/write')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(SftpException::class);
        $exceptions->dontFlash(['password', 'content', 'private_key', 'passphrase']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Friendly, stack-trace-free errors for the UI.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return $e instanceof SftpException
                    ? redirect()->route('home')->with('error', $e->getMessage())
                    : null;
            }

            return match (true) {
                $e instanceof SftpException => response()->json(array_filter([
                    'message' => $e->getMessage(),
                    'reconnect' => $e->status === 401 && $request->is('api/*') ? true : null,
                ]) + $e->context, $e->status),
                $e instanceof ValidationException => response()->json([
                    'message' => 'البيانات المدخلة غير صالحة.',
                    'errors' => $e->errors(),
                ], 422),
                $e instanceof HttpExceptionInterface => response()->json([
                    'message' => match ($e->getStatusCode()) {
                        419 => 'انتهت صلاحية الصفحة. أعد تحميل الصفحة.',
                        429 => 'محاولات كثيرة. انتظر دقيقة ثم حاول مجددًا.',
                        404 => 'غير موجود.',
                        default => 'حدث خطأ.',
                    },
                ], $e->getStatusCode()),
                str_starts_with($e::class, 'phpseclib3\\') => response()->json(['message' => SftpService::MSG_CONNECTION_LOST], 503),
                default => response()->json(['message' => 'حدث خطأ غير متوقع.'], 500),
            };
        });
    })->create();
