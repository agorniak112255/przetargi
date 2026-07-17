<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\PriceListImportController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductSubstituteController;
use App\Http\Controllers\Api\TenderConditionController;
use App\Http\Controllers\Api\TenderController;
use App\Http\Controllers\Api\TenderDocumentController;
use App\Http\Controllers\Api\TenderExportController;
use App\Http\Controllers\Api\TenderImportController;
use App\Http\Controllers\Api\TenderItemController;
use App\Http\Controllers\Api\TenderMatchController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', DashboardController::class);

    Route::get('/tenders', [TenderController::class, 'index']);
    Route::post('/tenders', [TenderController::class, 'store']);
    Route::get('/tenders/{tender}', [TenderController::class, 'show']);
    Route::post('/tenders/{tender}/transition', [TenderController::class, 'transition']);
    Route::post('/tenders/{tender}/import', [TenderImportController::class, 'store']);
    Route::get('/tenders/{tender}/documents', [TenderDocumentController::class, 'index']);
    Route::post('/tenders/{tender}/documents/analyze', [TenderDocumentController::class, 'analyze']);
    Route::post('/tenders/{tender}/documents/commit', [TenderDocumentController::class, 'commit']);
    Route::get('/tenders/{tender}/documents/{document}', [TenderDocumentController::class, 'show']);
    Route::post('/tenders/{tender}/documents/{document}/reanalyze', [TenderDocumentController::class, 'reanalyze']);
    Route::delete('/tenders/{tender}/documents/{document}', [TenderDocumentController::class, 'destroy']);
    Route::get('/tenders/{tender}/conditions', [TenderConditionController::class, 'index']);
    Route::post('/tenders/{tender}/conditions', [TenderConditionController::class, 'store']);
    Route::patch('/tenders/{tender}/conditions/{condition}', [TenderConditionController::class, 'update']);
    Route::delete('/tenders/{tender}/conditions/{condition}', [TenderConditionController::class, 'destroy']);
    Route::post('/tenders/{tender}/match', [TenderMatchController::class, 'store']);
    Route::post('/tenders/{tender}/items/{item}/match', [TenderMatchController::class, 'matchItem']);
    Route::get('/tenders/{tender}/export/excel', [TenderExportController::class, 'excel']);
    Route::get('/tenders/{tender}/export/pdf', [TenderExportController::class, 'pdf']);
    Route::post('/tenders/{tender}/items/bulk', [TenderItemController::class, 'bulkUpdate']);
    Route::patch('/tenders/{tender}/items/{item}', [TenderItemController::class, 'update']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    Route::get('/substitutes', [ProductSubstituteController::class, 'index']);
    Route::get('/products/{product}/substitutes', [ProductSubstituteController::class, 'byMain']);
    Route::patch('/substitutes/{productSubstitute}/approve', [ProductSubstituteController::class, 'approve']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::patch('/clients/{client}', [ClientController::class, 'update']);

    Route::get('/price-lists', [PriceListController::class, 'index']);
    Route::get('/price-lists/{priceList}', [PriceListController::class, 'show']);
    Route::post('/price-lists/analyze', [PriceListImportController::class, 'analyze']);
    Route::post('/price-lists/import', [PriceListImportController::class, 'store']);

    Route::get('/ai-settings', [AiSettingsController::class, 'show']);
    Route::put('/ai-settings', [AiSettingsController::class, 'update']);
    Route::post('/ai-settings/test', [AiSettingsController::class, 'test']);
});


