<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\JobTitleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register-company', [AuthController::class, 'registerCompany']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('job-titles', JobTitleController::class);
        Route::apiResource('employees', EmployeeController::class);
    });
});
