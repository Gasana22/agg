<?php

use App\Modules\Access\Http\Middleware\RequireFarmPermission;
use App\Modules\Identity\Http\Middleware\EnsureMfaCompliant;
use App\Modules\Platform\Http\Middleware\RequirePlatformAdmin;
use App\Modules\Platform\Http\Middleware\RequirePlatformCapability;
use App\Modules\Tenancy\Http\Middleware\ResolveFarmContext;
use App\Support\Http\Middleware\AssignRequestId;
use App\Support\Http\Middleware\EnforceIdempotency;
use App\Support\Http\ProblemRenderer;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->api(append: [EnforceIdempotency::class]);

        $middleware->alias([
            'mfa.compliant' => EnsureMfaCompliant::class,
            'farm' => ResolveFarmContext::class,
            'farm.can' => RequireFarmPermission::class,
            'platform.admin' => RequirePlatformAdmin::class,
            'platform.can' => RequirePlatformCapability::class,
        ]);

        // Tenant context must exist before route-model binding, so that bound
        // models are resolved through the farm scope (docs/02 §2 layer 4).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveFarmContext::class);

        // Idempotency runs after authentication (the key is per user).
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnforceIdempotency::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ProblemRenderer);
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
    })->create();
