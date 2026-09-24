<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\VerifyFirebaseToken;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'firebase.auth' => \App\Http\Middleware\VerifyFirebaseToken::class,
            'actor' => \App\Http\Middleware\EnsureActorType::class,
        ]);
    })

    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'verify.firebase' => VerifyFirebaseToken::class,
            'verify.device' => \App\Http\Middleware\VerifyDeviceSecret::class,
        ]);
    })
    
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    
    ->withCommands([
        \App\Console\Commands\MigrateTeacherAssignments::class,
        \App\Console\Commands\CleanupLegacyReportCardFlags::class,
        \App\Console\Commands\MigrateMapehToComponents::class,
        \App\Console\Commands\ReportMapehForte::class,
        \App\Console\Commands\RemirrorReleasedCards::class,
        \App\Console\Commands\MigrateStudentArchivedAt::class,
        \App\Console\Commands\MigrateParentArchivedAt::class,
        \App\Console\Commands\MigrateSubjectCodesPhase1::class,
        \App\Console\Commands\CleanupOrphanedMaCode::class,
        \App\Console\Commands\MigrateTeacherAssignmentsPhase2::class,
        \App\Console\Commands\MigrateLockedTerms::class,
        \App\Console\Commands\FixLockedTermsStorage::class,
    ])->create();

    
