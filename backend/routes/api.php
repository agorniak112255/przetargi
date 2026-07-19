<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\PriceListImportController;
use App\Http\Controllers\Api\ProductAiSearchController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductEnrichmentController;
use App\Http\Controllers\Api\ProductSubstituteController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TenderActivityController;
use App\Http\Controllers\Api\TenderCommentController;
use App\Http\Controllers\Api\TenderConditionController;
use App\Http\Controllers\Api\TenderController;
use App\Http\Controllers\Api\TenderCoverageController;
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

    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view');
    Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware('permission:reports.view');
    Route::get('/reports/csv', [ReportController::class, 'csv'])->middleware('permission:reports.view');

    Route::get('/tenders', [TenderController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::post('/tenders', [TenderController::class, 'store'])->middleware('permission:tenders.create');
    Route::get('/tenders/{tender}', [TenderController::class, 'show'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::patch('/tenders/{tender}', [TenderController::class, 'update'])->middleware('permission:tenders.create|tenders.edit_offer');
    Route::post('/tenders/{tender}/transition', [TenderController::class, 'transition']);
    Route::get('/tenders/{tender}/coverage', TenderCoverageController::class)->middleware('permission:tenders.view_own|tenders.view_all');
    Route::get('/tenders/{tender}/activities', [TenderActivityController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::get('/tenders/{tender}/comments', [TenderCommentController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::post('/tenders/{tender}/comments', [TenderCommentController::class, 'store'])->middleware('permission:tenders.comment');
    Route::delete('/tenders/{tender}/comments/{comment}', [TenderCommentController::class, 'destroy'])->middleware('permission:tenders.comment');
    Route::post('/tenders/{tender}/import', [TenderImportController::class, 'store'])->middleware('permission:tenders.import');
    Route::get('/tenders/{tender}/documents', [TenderDocumentController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::post('/tenders/{tender}/documents/analyze', [TenderDocumentController::class, 'analyze'])->middleware('permission:tenders.import');
    Route::post('/tenders/{tender}/documents/commit', [TenderDocumentController::class, 'commit'])->middleware('permission:tenders.import');
    Route::get('/tenders/{tender}/documents/{document}', [TenderDocumentController::class, 'show'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::post('/tenders/{tender}/documents/{document}/reanalyze', [TenderDocumentController::class, 'reanalyze'])->middleware('permission:tenders.import');
    Route::delete('/tenders/{tender}/documents/{document}', [TenderDocumentController::class, 'destroy'])->middleware('permission:tenders.import');
    Route::get('/tenders/{tender}/conditions', [TenderConditionController::class, 'index']);
    Route::post('/tenders/{tender}/conditions', [TenderConditionController::class, 'store'])->middleware('permission:tenders.edit_offer');
    Route::patch('/tenders/{tender}/conditions/{condition}', [TenderConditionController::class, 'update'])->middleware('permission:tenders.edit_offer');
    Route::delete('/tenders/{tender}/conditions/{condition}', [TenderConditionController::class, 'destroy'])->middleware('permission:tenders.edit_offer');
    Route::post('/tenders/{tender}/match', [TenderMatchController::class, 'store'])->middleware('permission:tenders.edit_offer');
    Route::post('/tenders/{tender}/items/{item}/match', [TenderMatchController::class, 'matchItem'])->middleware('permission:tenders.edit_offer');
    Route::get('/tenders/{tender}/export/excel', [TenderExportController::class, 'excel'])->middleware('permission:tenders.export');
    Route::get('/tenders/{tender}/export/pdf', [TenderExportController::class, 'pdf'])->middleware('permission:tenders.export');
    Route::get('/tenders/{tender}/export/docx', [TenderExportController::class, 'docx'])->middleware('permission:tenders.export');
    Route::post('/tenders/{tender}/items/bulk', [TenderItemController::class, 'bulkUpdate'])->middleware('permission:tenders.edit_offer');
    Route::patch('/tenders/{tender}/items/{item}', [TenderItemController::class, 'update'])->middleware('permission:tenders.edit_offer');

    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.view');
    Route::post('/products/ai-search', ProductAiSearchController::class)->middleware('permission:products.view');
    Route::post('/products/enrich', [ProductEnrichmentController::class, 'enrichProducts'])
        ->middleware('permission:price_lists.import');
    Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('permission:products.view');
    Route::get('/products/{product}/price-history', [ProductController::class, 'priceHistory'])->middleware('permission:products.view');
    Route::post('/products/{product}/enrich', [ProductEnrichmentController::class, 'enrichProduct'])
        ->middleware('permission:price_lists.import');
    Route::get('/product-enrichment-batches/active', [ProductEnrichmentController::class, 'activeBatches'])
        ->middleware('permission:price_lists.import|products.view');
    Route::get('/product-enrichment-batches/{batch}', [ProductEnrichmentController::class, 'showBatch'])
        ->middleware('permission:price_lists.import|products.view');

    Route::get('/substitutes', [ProductSubstituteController::class, 'index'])->middleware('permission:products.view');
    Route::get('/products/{product}/substitutes', [ProductSubstituteController::class, 'byMain'])->middleware('permission:products.view');
    Route::post('/substitutes', [ProductSubstituteController::class, 'store'])->middleware('permission:substitutes.manage');
    Route::patch('/substitutes/{productSubstitute}', [ProductSubstituteController::class, 'update'])->middleware('permission:substitutes.manage');
    Route::delete('/substitutes/{productSubstitute}', [ProductSubstituteController::class, 'destroy'])->middleware('permission:substitutes.manage');
    Route::patch('/substitutes/{productSubstitute}/approve', [ProductSubstituteController::class, 'approve'])->middleware('permission:substitutes.approve');

    Route::get('/clients', [ClientController::class, 'index'])->middleware('permission:clients.view');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('permission:clients.manage');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.manage');

    Route::get('/price-lists', [PriceListController::class, 'index'])->middleware('permission:price_lists.view');
    Route::get('/price-lists/{priceList}', [PriceListController::class, 'show'])->middleware('permission:price_lists.view');
    Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy'])
        ->middleware('permission:price_lists.delete');
    Route::post('/price-lists/analyze', [PriceListImportController::class, 'analyze'])->middleware('permission:price_lists.import');
    Route::post('/price-lists/import', [PriceListImportController::class, 'store'])->middleware('permission:price_lists.import');
    Route::post('/price-lists/{priceList}/enrich', [ProductEnrichmentController::class, 'enrichPriceList'])
        ->middleware('permission:price_lists.import');

    Route::get('/ai-settings', [AiSettingsController::class, 'show'])->middleware('permission:ai_settings.manage');
    Route::put('/ai-settings', [AiSettingsController::class, 'update'])->middleware('permission:ai_settings.manage');
    Route::post('/ai-settings/test', [AiSettingsController::class, 'test'])->middleware('permission:ai_settings.manage');
    Route::post('/ai-settings/test-vector', [AiSettingsController::class, 'testVector'])->middleware('permission:ai_settings.manage');

    Route::middleware('permission:admin.access')->prefix('admin')->group(function (): void {
        Route::get('/users', [AdminUserController::class, 'index'])->middleware('permission:admin.users.manage');
        Route::post('/users', [AdminUserController::class, 'store'])->middleware('permission:admin.users.manage');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])->middleware('permission:admin.users.manage');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->middleware('permission:admin.users.manage');

        Route::get('/roles', [AdminRoleController::class, 'index'])->middleware('permission:admin.roles.manage');
        Route::post('/roles', [AdminRoleController::class, 'store'])->middleware('permission:admin.roles.manage');
        Route::put('/roles/{role}', [AdminRoleController::class, 'update'])->middleware('permission:admin.roles.manage');
        Route::delete('/roles/{role}', [AdminRoleController::class, 'destroy'])->middleware('permission:admin.roles.manage');
    });
});
