<?php

use App\Http\Controllers\Api\V1\AllocationRuleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\HouseController;
use App\Http\Controllers\Api\V1\HouseMemberController;
use App\Http\Controllers\Api\V1\MonthlySettlementController;
use App\Http\Controllers\Api\V1\SettlementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/houses', [HouseController::class, 'index']);
        Route::post('/houses', [HouseController::class, 'store']);
        Route::get('/houses/{house}', [HouseController::class, 'show']);
        Route::put('/houses/{house}', [HouseController::class, 'update']);

        Route::get('/houses/{house}/members', [HouseMemberController::class, 'index']);
        Route::post('/houses/{house}/members', [HouseMemberController::class, 'store']);
        Route::post('/houses/{house}/members/{user}/leave', [HouseMemberController::class, 'leave']);

        Route::get('/houses/{house}/members/{user}/availability', [AvailabilityController::class, 'index']);
        Route::post('/houses/{house}/members/{user}/availability', [AvailabilityController::class, 'store']);

        Route::get('/houses/{house}/categories', [ExpenseCategoryController::class, 'index']);
        Route::post('/houses/{house}/categories', [ExpenseCategoryController::class, 'store']);
        Route::put('/categories/{category}', [ExpenseCategoryController::class, 'update']);

        Route::get('/categories/{category}/rules', [AllocationRuleController::class, 'index']);
        Route::post('/categories/{category}/rules', [AllocationRuleController::class, 'store']);
        Route::post('/categories/{category}/rules/versions', [AllocationRuleController::class, 'storeVersion']);

        Route::get('/houses/{house}/expenses', [ExpenseController::class, 'index']);
        Route::post('/houses/{house}/expenses', [ExpenseController::class, 'store']);
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
        Route::post('/expenses/{expense}/confirm', [ExpenseController::class, 'confirm']);
        Route::post('/expenses/{expense}/reinstate', [ExpenseController::class, 'reinstate']);
        Route::get('/expenses/{expense}/allocations', [ExpenseController::class, 'allocations']);

        Route::get('/houses/{house}/settlement', [SettlementController::class, 'show']);
        Route::get('/houses/{house}/months/{month}', [MonthlySettlementController::class, 'show'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('/houses/{house}/months/{month}/close', [MonthlySettlementController::class, 'close'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('/houses/{house}/months/{month}/reopen', [MonthlySettlementController::class, 'reopen'])
            ->where('month', '\d{4}-\d{2}');
    });
});
