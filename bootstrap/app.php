<?php

// CODEX: 25 linhas alteradas neste arquivo; mensagens em português.

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withProviders([
        LaravelServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // CODEX: o resumo padrão do Laravel acrescenta "(and N more errors)" mesmo com validação traduzida.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson() || $e->response !== null) {
                return null;
            }

            $mensagens = collect($e->errors())->flatten()->filter(fn ($valor): bool => is_string($valor))->values();
            $principal = $mensagens->first() ?? 'Os dados informados são inválidos.';
            $restantes = $mensagens->count() - 1;
            $sufixo = $restantes > 0
                ? ' (e mais '.$restantes.($restantes === 1 ? ' erro)' : ' erros)')
                : '';

            return response()->json([
                'message' => $principal.$sufixo,
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                // CODEX: mantém também as falhas de autenticação em português.
                'message' => 'Não autenticado.',
            ], 401);
        });
    })->create();
