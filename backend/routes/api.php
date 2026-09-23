<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AiTuningController as AdminAiTuningController;
use App\Http\Controllers\Api\Admin\BrandDictionaryController as AdminBrandDictionaryController;
use App\Http\Controllers\Api\Admin\CatalogSearchSiteController as AdminCatalogSearchSiteController;
use App\Http\Controllers\Api\Admin\CatalogSlangController as AdminCatalogSlangController;
use App\Http\Controllers\Api\Admin\EnrichmentDescriptionTemplateController as AdminEnrichmentDescriptionTemplateController;
use App\Http\Controllers\Api\Admin\MailSettingsController as AdminMailSettingsController;
use App\Http\Controllers\Api\Admin\PrestaCategoryController as AdminPrestaCategoryController;
use App\Http\Controllers\Api\Admin\PrestaShopSettingsController as AdminPrestaShopSettingsController;
use App\Http\Controllers\Api\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\B2bAccountController;
use App\Http\Controllers\Api\B2bDiscountRuleController;
use App\Http\Controllers\Api\B2bManufacturerRuleController;
use App\Http\Controllers\Api\CardMatchController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientInquiryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PrestaExportController;
use App\Http\Controllers\Api\PrestaShopSearchController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\PriceListImportController;
use App\Http\Controllers\Api\ProductAiSearchController;
use App\Http\Controllers\Api\ProductCardConflictsAiController;
use App\Http\Controllers\Api\ProductCatalogHealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductCrossRefController;
use App\Http\Controllers\Api\ProductEnrichmentController;
use App\Http\Controllers\Api\ProductImageController;
use App\Http\Controllers\Api\ProductImageThumbController;
use App\Http\Controllers\Api\ProductKitController;
use App\Http\Controllers\Api\ProductRequirementCheckController;
use App\Http\Controllers\Api\ProductRequirementTermsController;
use App\Http\Controllers\Api\ProductSubstituteController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchEventActionController;
use App\Http\Controllers\Api\TenderActivityController;
use App\Http\Controllers\Api\TenderBattlecardController;
use App\Http\Controllers\Api\TenderCommentController;
use App\Http\Controllers\Api\TenderConditionController;
use App\Http\Controllers\Api\TenderConflictsController;
use App\Http\Controllers\Api\TenderController;
use App\Http\Controllers\Api\TenderCoverageController;
use App\Http\Controllers\Api\TenderDocumentController;
use App\Http\Controllers\Api\TenderExportController;
use App\Http\Controllers\Api\TenderImportController;
use App\Http\Controllers\Api\TenderInvitationController;
use App\Http\Controllers\Api\TenderItemController;
use App\Http\Controllers\Api\TenderMatchController;
use App\Http\Controllers\Api\UserDirectoryController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/product-images/{image}/thumb', [ProductImageThumbController::class, 'show'])
    ->whereNumber('image')
    ->name('product-images.thumb');

Route::middleware(['auth:sanctum', 'log.activity'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me/preferences', [AuthController::class, 'updatePreferences']);
    Route::patch('/me/margin', [AuthController::class, 'updateDefaultMargin']);
    Route::post('/me/password', [AuthController::class, 'updatePassword']);
    Route::post('/me/presence', [AuthController::class, 'presence']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view');
    Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware('permission:reports.view');
    Route::get('/reports/csv', [ReportController::class, 'csv'])->middleware('permission:reports.view');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/users/directory', UserDirectoryController::class)->middleware('permission:tenders.invite');

    Route::get('/tenders', [TenderController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::post('/tenders', [TenderController::class, 'store'])->middleware('permission:tenders.create');

    Route::middleware(['permission:tenders.view_own|tenders.view_all', 'tender.access'])->group(function (): void {
        Route::get('/tenders/{tender}', [TenderController::class, 'show']);
        Route::patch('/tenders/{tender}', [TenderController::class, 'update'])->middleware('permission:tenders.create|tenders.edit_offer');
        Route::delete('/tenders/{tender}', [TenderController::class, 'destroy'])->middleware('permission:tenders.delete');
        Route::post('/tenders/{tender}/transition', [TenderController::class, 'transition']);
        Route::get('/tenders/{tender}/coverage', TenderCoverageController::class);
        Route::get('/tenders/{tender}/conflicts', TenderConflictsController::class);
        Route::get('/tenders/{tender}/activities', [TenderActivityController::class, 'index']);
        Route::get('/tenders/{tender}/comments', [TenderCommentController::class, 'index']);
        Route::post('/tenders/{tender}/comments', [TenderCommentController::class, 'store'])->middleware('permission:tenders.comment');
        Route::delete('/tenders/{tender}/comments/{comment}', [TenderCommentController::class, 'destroy'])->middleware('permission:tenders.comment');
        Route::post('/tenders/{tender}/import', [TenderImportController::class, 'store'])->middleware('permission:tenders.import');
        Route::get('/tenders/{tender}/documents', [TenderDocumentController::class, 'index']);
        Route::post('/tenders/{tender}/documents/analyze', [TenderDocumentController::class, 'analyze'])->middleware('permission:tenders.import');
        Route::post('/tenders/{tender}/documents/commit', [TenderDocumentController::class, 'commit'])->middleware('permission:tenders.import');
        Route::get('/tenders/{tender}/documents/{document}', [TenderDocumentController::class, 'show']);
        Route::get('/tenders/{tender}/documents/{document}/download', [TenderDocumentController::class, 'download']);
        Route::post('/tenders/{tender}/documents/{document}/reanalyze', [TenderDocumentController::class, 'reanalyze'])->middleware('permission:tenders.import');
        Route::delete('/tenders/{tender}/documents/{document}', [TenderDocumentController::class, 'destroy'])->middleware('permission:tenders.import');
        Route::get('/tenders/{tender}/conditions', [TenderConditionController::class, 'index']);
        Route::post('/tenders/{tender}/conditions', [TenderConditionController::class, 'store'])->middleware('permission:tenders.edit_offer');
        Route::patch('/tenders/{tender}/conditions/{condition}', [TenderConditionController::class, 'update'])->middleware('permission:tenders.edit_offer');
        Route::delete('/tenders/{tender}/conditions/{condition}', [TenderConditionController::class, 'destroy'])->middleware('permission:tenders.edit_offer');
        Route::post('/tenders/{tender}/match', [TenderMatchController::class, 'store'])->middleware('permission:tenders.edit_offer');
        Route::get('/tenders/{tender}/match/progress', [TenderMatchController::class, 'progress'])->middleware('permission:tenders.edit_offer');
        Route::post('/tenders/{tender}/items/{item}/match', [TenderMatchController::class, 'matchItem'])->middleware('permission:tenders.edit_offer');
        Route::get('/tenders/{tender}/items/{item}/battlecard', [TenderBattlecardController::class, 'show']);
        Route::get('/tenders/{tender}/export/excel', [TenderExportController::class, 'excel'])->middleware('permission:tenders.export');
        Route::get('/tenders/{tender}/export/pdf', [TenderExportController::class, 'pdf'])->middleware('permission:tenders.export');
        Route::get('/tenders/{tender}/export/docx', [TenderExportController::class, 'docx'])->middleware('permission:tenders.export');
        Route::post('/tenders/{tender}/items/bulk', [TenderItemController::class, 'bulkUpdate'])->middleware('permission:tenders.edit_offer');
        Route::post('/tenders/{tender}/items/apply-cheaper-substitutes', [TenderItemController::class, 'applyCheaperSubstitutes'])
            ->middleware('permission:tenders.edit_offer');
        Route::patch('/tenders/{tender}/items/{item}', [TenderItemController::class, 'update'])->middleware('permission:tenders.edit_offer');
        Route::delete('/tenders/{tender}/items/{item}', [TenderItemController::class, 'destroy'])->middleware('permission:tenders.delete_items');

        Route::get('/tenders/{tender}/invitations', [TenderInvitationController::class, 'index']);
        Route::post('/tenders/{tender}/invitations', [TenderInvitationController::class, 'store'])
            ->middleware('permission:tenders.invite');
        Route::delete('/tenders/{tender}/invitations/{invitation}', [TenderInvitationController::class, 'destroy'])
            ->middleware('permission:tenders.invite');
    });

    Route::get('/exchange-rates', ExchangeRateController::class)->middleware('permission:products.view');
    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.view');
    Route::get('/products/manufacturers', [ProductController::class, 'manufacturers'])->middleware('permission:products.view');
    Route::get('/products/categories', [ProductController::class, 'categoryOptions'])->middleware('permission:products.view');
    Route::patch('/products/{product}/category', [ProductController::class, 'updateCategory'])->middleware('permission:products.view');
    Route::patch('/products/{product}/manual-specs', [ProductController::class, 'updateManualSpecs'])
        ->middleware('permission:products.view');
    Route::patch('/products/{product}/shop-source', [ProductController::class, 'updateShopSource'])
        ->middleware('permission:price_lists.import');
    Route::get('/products/catalog-health', [ProductCatalogHealthController::class, 'show'])
        ->middleware('permission:products.view');
    Route::get('/products/catalog-health/vector', [ProductCatalogHealthController::class, 'vector'])
        ->middleware('permission:products.view');
    Route::post('/products/catalog-health/queue', [ProductCatalogHealthController::class, 'queue'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/catalog-health/backfill-attributes', [ProductCatalogHealthController::class, 'backfillAttributes'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/catalog-health/backfill-sizes', [ProductCatalogHealthController::class, 'backfillSizes'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/catalog-health/merge-sizes', [ProductCatalogHealthController::class, 'mergeSizes'])
        ->middleware('permission:price_lists.import');
    Route::get('/products/cross-ref/options', [ProductCrossRefController::class, 'options'])->middleware('permission:products.view');
    Route::get('/products/cross-ref', [ProductCrossRefController::class, 'crossRef'])->middleware('permission:products.view');
    Route::get('/products/compare', [ProductCrossRefController::class, 'compare'])->middleware('permission:products.view');
    Route::post('/products/ai-search', ProductAiSearchController::class)->middleware('permission:products.view');
    Route::post('/products/ai-search/{searchEvent}/action', [SearchEventActionController::class, 'store'])
        ->middleware('permission:products.view');
    Route::post('/products/requirement-terms', ProductRequirementTermsController::class)->middleware('permission:products.view');
    Route::post('/products/{product}/requirement-check', ProductRequirementCheckController::class)->middleware('permission:products.view');
    Route::post('/products/{product}/conflicts/ai', ProductCardConflictsAiController::class)->middleware('permission:products.view');
    Route::post('/products/enrich', [ProductEnrichmentController::class, 'enrichProducts'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/delete', [ProductController::class, 'destroyMany'])
        ->middleware('permission:products.delete');
    Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('permission:products.view');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])
        ->middleware('permission:products.delete');
    Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
        ->whereNumber('image')
        ->middleware('permission:products.images.delete');
    Route::post('/products/{product}/kit-suggestions', [ProductKitController::class, 'suggest'])->middleware('permission:products.view');
    Route::post('/products/{product}/kit', [ProductKitController::class, 'attach'])->middleware('permission:products.view');
    Route::delete('/products/{product}/kit', [ProductKitController::class, 'destroy'])->middleware('permission:products.view');
    Route::get('/products/{product}/price-history', [ProductController::class, 'priceHistory'])->middleware('permission:products.view');
    Route::get('/products/{product}/variants/{variant}/price-history', [ProductController::class, 'variantPriceHistory'])->middleware('permission:products.view');
    Route::post('/products/{product}/enrich', [ProductEnrichmentController::class, 'enrichProduct'])
        ->middleware('permission:price_lists.import');
    Route::get('/presta/status', [PrestaShopSearchController::class, 'status'])
        ->middleware('permission:price_lists.import|products.view');
    Route::post('/products/presta-search', [PrestaShopSearchController::class, 'searchProducts'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/{product}/presta-search', [PrestaShopSearchController::class, 'searchProduct'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/presta-apply-batch', [PrestaShopSearchController::class, 'applyBatch'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/{product}/presta-apply', [PrestaShopSearchController::class, 'apply'])
        ->middleware('permission:price_lists.import');
    Route::post('/products/{product}/presta-export', [PrestaExportController::class, 'exportProduct'])
        ->middleware('permission:presta.export');
    Route::post('/products/presta-export', [PrestaExportController::class, 'exportProducts'])
        ->middleware('permission:presta.export');
    Route::get('/product-enrichment/limits', [ProductEnrichmentController::class, 'limits'])
        ->middleware('permission:price_lists.import|products.view');
    Route::get('/product-enrichment-batches/active', [ProductEnrichmentController::class, 'activeBatches'])
        ->middleware('permission:price_lists.import|products.view');
    Route::get('/product-enrichment-batches/history', [ProductEnrichmentController::class, 'historyBatches'])
        ->middleware('permission:admin.enrichment.view|admin.access|price_lists.import|products.view');
    Route::post('/product-enrichment-batches/stop-all', [ProductEnrichmentController::class, 'stopAll'])
        ->middleware('permission:price_lists.import');
    Route::get('/product-enrichment-batches/{batch}', [ProductEnrichmentController::class, 'showBatch'])
        ->middleware('permission:price_lists.import|products.view');
    Route::get('/product-enrichment-batches/{batch}/items', [ProductEnrichmentController::class, 'batchItems'])
        ->middleware('permission:price_lists.import|products.view');
    Route::post('/product-enrichment-batches/{batch}/items/{product}', [ProductEnrichmentController::class, 'processBatchItem'])
        ->middleware('permission:price_lists.import');
    Route::post('/product-enrichment-batches/{batch}/cancel', [ProductEnrichmentController::class, 'cancelBatch'])
        ->middleware('permission:price_lists.import');

    Route::get('/substitutes', [ProductSubstituteController::class, 'index'])->middleware('permission:products.view');
    Route::get('/products/{product}/substitutes', [ProductSubstituteController::class, 'byMain'])->middleware('permission:products.view');
    Route::post('/substitutes', [ProductSubstituteController::class, 'store'])->middleware('permission:substitutes.manage');
    Route::patch('/substitutes/{productSubstitute}', [ProductSubstituteController::class, 'update'])->middleware('permission:substitutes.manage');
    Route::delete('/substitutes/{productSubstitute}', [ProductSubstituteController::class, 'destroy'])->middleware('permission:substitutes.manage');
    Route::patch('/substitutes/{productSubstitute}/approve', [ProductSubstituteController::class, 'approve'])->middleware('permission:substitutes.approve');

    // Łączenie kart dystrybutora z kartą producenta (plan łączenia kart, etap C) — decyzja człowieka;
    // uprawnienia osobne od katalogu produktów, nadawane rolom w Administracja → Role
    Route::get('/card-matches', [CardMatchController::class, 'index'])->middleware('permission:card_matches.view');
    Route::get('/card-matches/summary', [CardMatchController::class, 'summary'])->middleware('permission:card_matches.view');
    Route::post('/card-matches/refresh', [CardMatchController::class, 'refresh'])->middleware('permission:card_matches.decide');
    Route::post('/card-matches/bulk', [CardMatchController::class, 'bulk'])->middleware('permission:card_matches.decide');
    Route::post('/card-matches/{candidate}/merge', [CardMatchController::class, 'merge'])
        ->whereNumber('candidate')
        ->middleware('permission:card_matches.decide');
    Route::post('/card-matches/{candidate}/reject', [CardMatchController::class, 'reject'])
        ->whereNumber('candidate')
        ->middleware('permission:card_matches.decide');

    Route::get('/clients', [ClientController::class, 'index'])->middleware('permission:clients.view');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('permission:clients.manage');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.manage');

    Route::middleware('permission:inquiries.use')->group(function (): void {
        Route::get('/inquiries', [ClientInquiryController::class, 'index']);
        Route::post('/inquiries', [ClientInquiryController::class, 'store']);
        // przed „{inquiry}”, żeby stałe ścieżki nie zostały wzięte za numer zapytania
        Route::get('/inquiries/preferences', [ClientInquiryController::class, 'preferences']);
        Route::get('/inquiries/queued', [ClientInquiryController::class, 'queued']);
        // dodatek do Thunderbirda: które maile mają już zapytanie i kto je prowadzi
        Route::post('/inquiries/lookup', [ClientInquiryController::class, 'lookup']);
        Route::get('/inquiries/message-ids', [ClientInquiryController::class, 'messageIds']);
        Route::get('/inquiries/{inquiry}', [ClientInquiryController::class, 'show']);
        Route::patch('/inquiries/{inquiry}', [ClientInquiryController::class, 'update']);
        Route::post('/inquiries/{inquiry}/compose', [ClientInquiryController::class, 'compose']);
        Route::post('/inquiries/{inquiry}/pick-product', [ClientInquiryController::class, 'pickProduct']);
        Route::post('/inquiries/{inquiry}/replied', [ClientInquiryController::class, 'replied']);
        Route::post('/inquiries/{inquiry}/queue-reply', [ClientInquiryController::class, 'queueReply']);
        // kasuje tylko autor zapytania — sprawdzenie w kontrolerze
        Route::delete('/inquiries/{inquiry}', [ClientInquiryController::class, 'destroy']);
    });

    Route::get('/price-lists', [PriceListController::class, 'index'])->middleware('permission:price_lists.view');
    Route::get('/price-lists/{priceList}', [PriceListController::class, 'show'])->middleware('permission:price_lists.view');
    Route::patch('/price-lists/{priceList}', [PriceListController::class, 'update'])
        ->middleware('permission:price_lists.import');
    Route::get('/price-lists/{priceList}/discounts', [PriceListController::class, 'discounts'])
        ->middleware('permission:price_lists.import');
    Route::put('/price-lists/{priceList}/discounts', [PriceListController::class, 'updateDiscounts'])
        ->middleware('permission:price_lists.import');
    Route::delete('/price-lists/{priceList}/imports/{import}', [PriceListController::class, 'destroyImport'])
        ->middleware('permission:price_lists.delete');
    Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy'])
        ->middleware('permission:price_lists.delete');
    Route::post('/price-lists/analyze', [PriceListImportController::class, 'analyze'])->middleware('permission:price_lists.import');
    Route::post('/price-lists/preview', [PriceListImportController::class, 'preview'])->middleware('permission:price_lists.import');
    Route::post('/price-lists/import', [PriceListImportController::class, 'store'])->middleware('permission:price_lists.import');
    Route::post('/price-lists/{priceList}/enrich', [ProductEnrichmentController::class, 'enrichPriceList'])
        ->middleware('permission:price_lists.import');
    Route::post('/price-lists/{priceList}/presta-export', [PrestaExportController::class, 'exportPriceList'])
        ->middleware('permission:presta.export');

    Route::get('/b2b-accounts', [B2bAccountController::class, 'index'])->middleware('permission:b2b_accounts.view');
    Route::post('/b2b-accounts', [B2bAccountController::class, 'store'])->middleware('permission:b2b_accounts.manage');
    Route::patch('/b2b-accounts/{b2bAccount}', [B2bAccountController::class, 'update'])->middleware('permission:b2b_accounts.manage');
    Route::delete('/b2b-accounts/{b2bAccount}', [B2bAccountController::class, 'destroy'])->middleware('permission:b2b_accounts.manage');
    Route::post('/b2b-accounts/{b2bAccount}/password', [B2bAccountController::class, 'revealPassword'])
        ->middleware('permission:b2b_accounts.view');
    Route::post('/b2b-accounts/{b2bAccount}/sync', [B2bAccountController::class, 'requestSync'])
        ->middleware('permission:b2b_accounts.manage');
    Route::post('/b2b-accounts/{b2bAccount}/login-code', [B2bAccountController::class, 'startLoginCode'])
        ->middleware('permission:b2b_accounts.manage');
    Route::post('/b2b-accounts/{b2bAccount}/login-code/verify', [B2bAccountController::class, 'verifyLoginCode'])
        ->middleware('permission:b2b_accounts.manage');
    Route::get('/b2b-accounts/{b2bAccount}/sync-progress', [B2bAccountController::class, 'syncProgress'])
        ->middleware('permission:b2b_accounts.view');
    Route::post('/b2b-accounts/{b2bAccount}/sync-cancel', [B2bAccountController::class, 'cancelSync'])
        ->middleware('permission:b2b_accounts.manage');
    Route::get('/b2b-accounts/{b2bAccount}/discount-rules', [B2bDiscountRuleController::class, 'index'])
        ->middleware('permission:b2b_accounts.view');
    Route::put('/b2b-accounts/{b2bAccount}/discount-rules', [B2bDiscountRuleController::class, 'update'])
        ->middleware('permission:b2b_accounts.manage');
    // loguje się u dostawcy i pobiera cennik bazowy — tylko dla zarządzających kontami
    Route::post('/b2b-accounts/{b2bAccount}/discount-rules/base-categories', [B2bDiscountRuleController::class, 'baseCategories'])
        ->middleware('permission:b2b_accounts.manage');
    Route::get('/b2b-accounts/{b2bAccount}/manufacturers', [B2bManufacturerRuleController::class, 'index'])
        ->middleware('permission:b2b_accounts.view');
    Route::put('/b2b-accounts/{b2bAccount}/manufacturers', [B2bManufacturerRuleController::class, 'update'])
        ->middleware('permission:b2b_accounts.manage');
    Route::get('/b2b-connectors', [B2bAccountController::class, 'connectors'])->middleware('permission:b2b_accounts.view');

    Route::get('/ai-settings', [AiSettingsController::class, 'show'])->middleware('permission:ai_settings.manage');
    Route::put('/ai-settings', [AiSettingsController::class, 'update'])->middleware('permission:ai_settings.manage');
    Route::get('/ai-settings/jina-usage', [AiSettingsController::class, 'jinaUsage'])->middleware('permission:ai_settings.manage');
    Route::post('/ai-settings/jina-usage/refresh', [AiSettingsController::class, 'refreshJinaUsage'])->middleware('permission:ai_settings.manage');
    Route::post('/ai-settings/test', [AiSettingsController::class, 'test'])->middleware('permission:ai_settings.manage');
    Route::post('/ai-settings/test-vector', [AiSettingsController::class, 'testVector'])->middleware('permission:ai_settings.manage');

    Route::middleware('permission:admin.access')->prefix('admin')->group(function (): void {
        Route::get('/users', [AdminUserController::class, 'index'])->middleware('permission:admin.users.manage');
        Route::post('/users', [AdminUserController::class, 'store'])->middleware('permission:admin.users.manage');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])->middleware('permission:admin.users.manage');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->middleware('permission:admin.users.manage');
        Route::post('/users/{user}/send-credentials', [AdminUserController::class, 'sendCredentials'])->middleware('permission:admin.users.manage');

        Route::get('/roles', [AdminRoleController::class, 'index'])->middleware('permission:admin.roles.manage');
        Route::post('/roles', [AdminRoleController::class, 'store'])->middleware('permission:admin.roles.manage');
        Route::put('/roles/{role}', [AdminRoleController::class, 'update'])->middleware('permission:admin.roles.manage');
        Route::delete('/roles/{role}', [AdminRoleController::class, 'destroy'])->middleware('permission:admin.roles.manage');

        Route::get('/activity-logs', [AdminActivityLogController::class, 'index'])
            ->middleware('permission:admin.activity.view');
        Route::get('/sessions', [AdminSessionController::class, 'index'])->middleware('permission:admin.sessions.view');
        Route::get('/users-activity', [AdminSessionController::class, 'users'])->middleware('permission:admin.sessions.view');

        Route::get('/mail-settings', [AdminMailSettingsController::class, 'show'])
            ->middleware('permission:admin.mail.manage');
        Route::put('/mail-settings', [AdminMailSettingsController::class, 'update'])
            ->middleware('permission:admin.mail.manage');
        Route::post('/mail-settings/test', [AdminMailSettingsController::class, 'test'])
            ->middleware('permission:admin.mail.manage');

        Route::middleware('permission:admin.presta.manage')->group(function (): void {
            Route::get('/presta-settings', [AdminPrestaShopSettingsController::class, 'show']);
            Route::put('/presta-settings', [AdminPrestaShopSettingsController::class, 'update']);
            Route::post('/presta-settings/test', [AdminPrestaShopSettingsController::class, 'test']);
            Route::get('/presta-categories', [AdminPrestaCategoryController::class, 'index']);
            Route::post('/presta-categories/sync', [AdminPrestaCategoryController::class, 'sync']);
            Route::post('/presta-categories/auto-map', [AdminPrestaCategoryController::class, 'autoMap']);
            Route::post('/presta-categories/apply', [AdminPrestaCategoryController::class, 'apply']);
            Route::post('/presta-categories/rewrite', [AdminPrestaCategoryController::class, 'rewrite']);
            Route::put('/presta-categories/maps', [AdminPrestaCategoryController::class, 'updateMaps']);
        });

        Route::middleware('permission:admin.ai_tuning.manage')->group(function (): void {
            Route::get('/ai-tuning', [AdminAiTuningController::class, 'show']);
            Route::put('/ai-tuning', [AdminAiTuningController::class, 'update']);
        });

        Route::middleware('permission:admin.catalog_slang.manage')->group(function (): void {
            Route::get('/catalog-slang', [AdminCatalogSlangController::class, 'show']);
            Route::put('/catalog-slang', [AdminCatalogSlangController::class, 'update']);
        });

        Route::middleware('permission:admin.dictionaries.manage')->group(function (): void {
            Route::get('/brand-dictionary', [AdminBrandDictionaryController::class, 'index']);
            Route::post('/brand-dictionary', [AdminBrandDictionaryController::class, 'store']);
            Route::patch('/brand-dictionary/{entry}', [AdminBrandDictionaryController::class, 'update'])->whereNumber('entry');
            Route::delete('/brand-dictionary/{entry}', [AdminBrandDictionaryController::class, 'destroy'])->whereNumber('entry');
        });

        Route::middleware('permission:admin.description_templates.manage')->group(function (): void {
            Route::get('/enrichment-description-templates', [AdminEnrichmentDescriptionTemplateController::class, 'index']);
            Route::put('/enrichment-description-templates/{kategoria}', [AdminEnrichmentDescriptionTemplateController::class, 'update'])
                ->where('kategoria', '[a-z_]+');
            Route::post('/enrichment-description-templates/{kategoria}/restore', [AdminEnrichmentDescriptionTemplateController::class, 'restore'])
                ->where('kategoria', '[a-z_]+');
        });

        Route::middleware('permission:admin.search_sites.manage')->group(function (): void {
            Route::get('/catalog-search-sites', [AdminCatalogSearchSiteController::class, 'index']);
            Route::post('/catalog-search-sites', [AdminCatalogSearchSiteController::class, 'store']);
            Route::get('/catalog-search-sites/product-lookup', [AdminCatalogSearchSiteController::class, 'lookup']);
            Route::get('/catalog-search-sites/{host}/pages', [AdminCatalogSearchSiteController::class, 'pages'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::get('/catalog-search-sites/{host}/progress', [AdminCatalogSearchSiteController::class, 'progress'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::post('/catalog-search-sites/{host}/reindex', [AdminCatalogSearchSiteController::class, 'reindex'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::post('/catalog-search-sites/{host}/unskip', [AdminCatalogSearchSiteController::class, 'unskip'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::post('/catalog-search-sites/{host}/reskip', [AdminCatalogSearchSiteController::class, 'reskip'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::post('/catalog-search-sites/{host}/manufacturer', [AdminCatalogSearchSiteController::class, 'assignManufacturer'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::delete('/catalog-search-sites/{host}/manufacturer', [AdminCatalogSearchSiteController::class, 'clearManufacturer'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::post('/catalog-search-sites/{host}/priority', [AdminCatalogSearchSiteController::class, 'setPriority'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::delete('/catalog-search-sites/{host}/priority', [AdminCatalogSearchSiteController::class, 'clearPriority'])
                ->where('host', '[A-Za-z0-9._-]+');
            Route::delete('/catalog-search-sites/{host}', [AdminCatalogSearchSiteController::class, 'destroy'])
                ->where('host', '[A-Za-z0-9._-]+');
        });
    });
});
