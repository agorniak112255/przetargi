<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminAiStatsController;
use App\Http\Controllers\Api\Admin\AiTuningController as AdminAiTuningController;
use App\Http\Controllers\Api\Admin\BrandDictionaryController as AdminBrandDictionaryController;
use App\Http\Controllers\Api\Admin\CatalogSearchSiteController as AdminCatalogSearchSiteController;
use App\Http\Controllers\Api\Admin\CatalogSlangController as AdminCatalogSlangController;
use App\Http\Controllers\Api\Admin\EnrichmentDescriptionTemplateController as AdminEnrichmentDescriptionTemplateController;
use App\Http\Controllers\Api\Admin\ErpEmployeeController;
use App\Http\Controllers\Api\Admin\ErpItemController;
use App\Http\Controllers\Api\Admin\ErpOperatorController;
use App\Http\Controllers\Api\Admin\LocalNetworkController as AdminLocalNetworkController;
use App\Http\Controllers\Api\Admin\MailSettingsController as AdminMailSettingsController;
use App\Http\Controllers\Api\Admin\PrestaCategoryController as AdminPrestaCategoryController;
use App\Http\Controllers\Api\Admin\PrestaShopSettingsController as AdminPrestaShopSettingsController;
use App\Http\Controllers\Api\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Api\Admin\SystemStatusController as AdminSystemStatusController;
use App\Http\Controllers\Api\Admin\TeamController as AdminTeamController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\B2bAccountController;
use App\Http\Controllers\Api\B2bDiscountRuleController;
use App\Http\Controllers\Api\B2bManufacturerRuleController;
use App\Http\Controllers\Api\CalendarFeedController;
use App\Http\Controllers\Api\CalendarIcsController;
use App\Http\Controllers\Api\CampaignAssetController;
use App\Http\Controllers\Api\CampaignClickController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CampaignReportController;
use App\Http\Controllers\Api\CampaignTemplateController;
use App\Http\Controllers\Api\CardMatchController;
use App\Http\Controllers\Api\Chat\ChatCallController;
use App\Http\Controllers\Api\Chat\ChatConversationController;
use App\Http\Controllers\Api\Chat\ChatMessageController;
use App\Http\Controllers\Api\Chat\ChatUserController;
use App\Http\Controllers\Api\Chat\LiveKitWebhookController;
use App\Http\Controllers\Api\Chat\RealtimeController;
use App\Http\Controllers\Api\ClientCardController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientInquiryController;
use App\Http\Controllers\Api\ClientNoteController;
use App\Http\Controllers\Api\CompetitorController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailSuppressionController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\ImportExclusionController;
use App\Http\Controllers\Api\InquiryOutcomeController;
use App\Http\Controllers\Api\InventoryBoardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryRwPwController;
use App\Http\Controllers\Api\MailingListController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OfferComposeController;
use App\Http\Controllers\Api\OfferController;
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
use App\Http\Controllers\Api\SalesTargetController;
use App\Http\Controllers\Api\SearchEventActionController;
use App\Http\Controllers\Api\TenderActivityController;
use App\Http\Controllers\Api\TenderBattlecardController;
use App\Http\Controllers\Api\TenderBzpController;
use App\Http\Controllers\Api\TenderCalendarController;
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
use App\Http\Controllers\Api\TenderResultController;
use App\Http\Controllers\Api\UnsubscribeController;
use App\Http\Controllers\Api\UserDirectoryController;
use App\Http\Controllers\Api\UserMailAccountController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/product-images/{image}/thumb', [ProductImageThumbController::class, 'show'])
    ->whereNumber('image')
    ->name('product-images.thumb');
Route::get('/product-images/{image}/square', [ProductImageThumbController::class, 'square'])
    ->whereNumber('image')
    ->name('product-images.square');
// Obrazki w mailach kampanii (logo, grafika) — publiczne, bo klient otwiera je bez logowania. Bez limitu zapytań
// (jak miniatury produktów): pośrednicy Gmaila/Outlooka pobierają obrazki wielu odbiorców z jednego adresu IP.
Route::get('/campaign-assets/{uuid}', [CampaignAssetController::class, 'show'])
    ->where('uuid', '[0-9a-f-]{36}')
    ->name('campaign-assets.show');

// Wypis z mailingu kampanii — publiczny link z maila. GET tylko pokazuje przycisk (skanery linków w poczcie klikają
// w GET), wypisuje dopiero POST: przycisk na stronie albo nagłówek List-Unsubscribe-Post (RFC 8058).
// Wypis jednym kliknięciem z Gmaila/Yahoo przychodzi ze wspólnych adresów dostawcy poczty — POST z wyższym limitem.
// Publiczne trasy z limitem mają własne przedrostki: bez nich licznik (klucz = domena|IP) jest wspólny dla wszystkich
// tras bez przedrostka — kliknięcia linków kampanii z pośrednika poczty zjadałyby limit wypisu i kalendarza.
Route::get('/wypis/{token}', [UnsubscribeController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,unsubscribe-page')->name('campaigns.unsubscribe');
Route::post('/wypis/{token}', [UnsubscribeController::class, 'confirm'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:300,1,unsubscribe-confirm');
// Linki z maila kampanii: zapis kliknięcia i przejście do „Zapytaj o ofertę” (mail) albo strony produktu
Route::middleware('throttle:300,1,campaign-links')->group(function (): void {
    Route::get('/k/{token}/o/{item}', [CampaignClickController::class, 'offer'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('item');
    Route::get('/k/{token}/p/{item}', [CampaignClickController::class, 'product'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('item');
    Route::get('/k/{token}/l/{item}', [CampaignClickController::class, 'link'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('item');
});

// Czat firmowy — POZA grupą `log.activity`: dziennik zapisuje ciało każdego udanego POST, a treść wiadomości
// i przekazanego maila nie może tam trafić. Limity mają własne przedrostki: bez nich throttle liczy wszystkie trasy
// użytkownika z tym samym limitem na jednym liczniku.
Route::middleware('auth:sanctum')->group(function (): void {
    // konfiguracja websocketu — także bez uprawnienia `chat` (dodatek do Thunderbirda słucha nim kolejki)
    Route::get('/realtime', RealtimeController::class);

    Route::middleware('permission:chat')->prefix('chat')->group(function (): void {
        Route::get('/users', [ChatUserController::class, 'index']);
        Route::get('/unread', [ChatConversationController::class, 'unread']);
        Route::get('/conversations', [ChatConversationController::class, 'index']);
        Route::post('/conversations', [ChatConversationController::class, 'store'])->middleware('throttle:10,1,chat-conversations');
        Route::get('/conversations/{conversation}', [ChatConversationController::class, 'show'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/participants', [ChatConversationController::class, 'addParticipants'])
            ->whereNumber('conversation');
        Route::post('/conversations/{conversation}/leave', [ChatConversationController::class, 'leave'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/read', [ChatConversationController::class, 'read'])->whereNumber('conversation');
        Route::get('/conversations/{conversation}/messages', [ChatMessageController::class, 'index'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])
            ->whereNumber('conversation')
            ->middleware('throttle:60,1,chat-messages');
        Route::post('/direct/{user}/messages', [ChatMessageController::class, 'storeDirect'])
            ->whereNumber('user')
            ->middleware('throttle:60,1,chat-messages');
        Route::delete('/messages/{message}', [ChatMessageController::class, 'destroy'])->whereNumber('message');
        // wyszukiwanie w treści i historia rozmowy (linki, maile, połączenia)
        Route::get('/search', [ChatMessageController::class, 'search']);

        // rozmowy głosowe i wideo (LiveKit)
        Route::get('/calls/config', [ChatCallController::class, 'config']);
        Route::post('/conversations/{conversation}/calls', [ChatCallController::class, 'store'])
            ->whereNumber('conversation')
            ->middleware('throttle:10,1,chat-calls');
        Route::get('/calls/{call}', [ChatCallController::class, 'show'])->whereNumber('call');
        Route::post('/calls/{call}/join', [ChatCallController::class, 'join'])->whereNumber('call');
        Route::post('/calls/{call}/decline', [ChatCallController::class, 'decline'])->whereNumber('call');
        Route::post('/calls/{call}/leave', [ChatCallController::class, 'leave'])->whereNumber('call');
    });
});

// Webhook serwera LiveKit (rozmowy w czacie) — bez auth:sanctum, bez limitu i poza `log.activity`: nadawcę potwierdza
// podpis JWT sekretem API; limit żądań gubiłby zdarzenia przy wielu osobach w rozmowie.
Route::post('/chat/livekit/webhook', LiveKitWebhookController::class);

// Kalendarz terminów do subskrypcji (Outlook, Thunderbird) — publiczny adres z tajnym kluczem: bez auth:sanctum
// i poza `log.activity` (klucz w adresie nie może trafić do dziennika). Uprawnienia właściciela adresu sprawdza
// kontroler przy każdym pobraniu; nieznany klucz → 404.
Route::get('/calendar/{token}.ics', [CalendarIcsController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:60,1,calendar-ics');

Route::middleware(['auth:sanctum', 'log.activity'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me/preferences', [AuthController::class, 'updatePreferences']);
    Route::patch('/me/margin', [AuthController::class, 'updateDefaultMargin']);
    Route::post('/me/password', [AuthController::class, 'updatePassword']);
    Route::post('/me/presence', [AuthController::class, 'presence']);
    // Moje konto › Powiadomienia: zdarzenia × dzwonek / e-mail i momenty przypomnień o terminie
    Route::get('/me/notification-preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('/me/notification-preferences', [NotificationPreferenceController::class, 'update']);
    // osobisty adres kalendarza terminów (ICS): stan, wygenerowanie nowego (adres widoczny raz), wyłączenie.
    // Stan i wyłączenie bez uprawnienia do przetargów — osoba, która je straciła, nadal może wyłączyć swój adres
    // (sam plik ICS i tak sprawdza uprawnienia przy każdym pobraniu); nowy adres tylko z uprawnieniem.
    Route::get('/me/calendar-feed', [CalendarFeedController::class, 'show']);
    Route::delete('/me/calendar-feed', [CalendarFeedController::class, 'destroy']);
    Route::post('/me/calendar-feed', [CalendarFeedController::class, 'store'])->middleware('permission:tenders.view_own|tenders.view_all');
    // własny cel sprzedaży na Dashboardzie — każdy widzi tylko swój
    Route::get('/me/sales-target', [SalesTargetController::class, 'mine']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view');
    // wynik kampanii: bez reports.view — dostęp i zakres (własne / zespół / wszyscy) sprawdza CampaignReportScope
    Route::get('/reports/campaigns', [CampaignReportController::class, 'index']);
    Route::get('/reports/campaigns/csv', [CampaignReportController::class, 'csv']);
    Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware('permission:reports.view');
    Route::get('/reports/csv', [ReportController::class, 'csv'])->middleware('permission:reports.view');
    Route::get('/reports/catalog', [ReportController::class, 'catalog'])->middleware('permission:reports.view');
    Route::get('/reports/prices', [ReportController::class, 'prices'])->middleware('permission:reports.view');
    Route::get('/reports/sources', [ReportController::class, 'sources'])->middleware('permission:reports.view');
    Route::get('/reports/sales', [ReportController::class, 'sales'])->middleware('permission:reports.view');
    Route::get('/reports/customers', [ReportController::class, 'customers'])->middleware('permission:reports.view');
    // skuteczność przetargów: poza reports.view kontroler wymaga tenders.view_own albo tenders.view_all
    Route::get('/reports/effectiveness', [ReportController::class, 'effectiveness'])->middleware('permission:reports.view');
    Route::get('/reports/effectiveness/csv', [ReportController::class, 'effectivenessCsv'])->middleware('permission:reports.view');
    // cele handlowców: zakładka Raportów tylko z reports.view i reports.targets.manage (oba wymagane)
    Route::middleware(['permission:reports.view', 'permission:reports.targets.manage'])->group(function (): void {
        Route::get('/reports/targets', [SalesTargetController::class, 'index']);
        Route::put('/reports/targets/{month}', [SalesTargetController::class, 'update'])->where('month', '\d{4}-\d{2}');
    });

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/users/directory', UserDirectoryController::class)->middleware('permission:tenders.invite');

    // jedno pole wyszukiwania (Ctrl+K) — każda grupa wyników sprawdza w kontrolerze własne uprawnienie
    Route::get('/search', GlobalSearchController::class);

    Route::get('/tenders', [TenderController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');
    // kalendarz terminów — przed „/tenders/{tender}”, żeby „calendar” nie został wzięty za numer przetargu
    Route::get('/tenders/calendar', [TenderCalendarController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');
    Route::post('/tenders', [TenderController::class, 'store'])->middleware('permission:tenders.create');
    // firmy konkurencji — podpowiedzi w wyniku przetargu
    Route::get('/competitors', [CompetitorController::class, 'index'])->middleware('permission:tenders.view_own|tenders.view_all');

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
        Route::get('/tenders/{tender}/mention-candidates', [TenderCommentController::class, 'mentionCandidates'])->middleware('permission:tenders.comment');
        // wynik przetargu per część; edycja jak oferta (tenders.edit_offer + dostęp do przetargu), bez ograniczenia statusem
        Route::get('/tenders/{tender}/result', [TenderResultController::class, 'show']);
        Route::put('/tenders/{tender}/result', [TenderResultController::class, 'update'])->middleware('permission:tenders.edit_offer');
        // część innego przetargu → 404 (scopeBindings: {lot} szukane w $tender->lots())
        Route::delete('/tenders/{tender}/result/lots/{lot}', [TenderResultController::class, 'destroyLot'])
            ->whereNumber('lot')
            ->scopeBindings()
            ->middleware('permission:tenders.edit_offer');
        Route::post('/tenders/{tender}/result/bzp-check', [TenderBzpController::class, 'check'])->middleware('permission:tenders.edit_offer');
        Route::post('/tenders/{tender}/import', [TenderImportController::class, 'store'])->middleware('permission:tenders.import');
        Route::get('/tenders/{tender}/documents', [TenderDocumentController::class, 'index']);
        Route::post('/tenders/{tender}/documents/analyze', [TenderDocumentController::class, 'analyze'])->middleware('permission:tenders.import');
        Route::post('/tenders/{tender}/documents/commit', [TenderDocumentController::class, 'commit'])->middleware('permission:tenders.import');
        // plik z platformy e-Zamówienia (dokument postępowania z ogłoszenia) → ta sama ścieżka co analyze, podgląd do zatwierdzenia
        Route::post('/tenders/{tender}/documents/from-notice', [TenderDocumentController::class, 'fromNotice'])->middleware('permission:tenders.import');
        Route::post('/tenders/{tender}/documents/from-notice-text', [TenderDocumentController::class, 'fromNoticeText'])->middleware('permission:tenders.import');
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
        Route::post('/tenders/{tender}/match/finish', [TenderMatchController::class, 'finish'])->middleware('permission:tenders.edit_offer');
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

    // zakładka Ogłoszenia: ogłoszenia o zamówieniu z Biuletynu, wspólne „pominięte”, przetarg z ogłoszenia
    Route::middleware('permission:notices.view')->group(function (): void {
        Route::get('/notices', [NoticeController::class, 'index']);
        Route::get('/notices/{notice}', [NoticeController::class, 'show'])->whereNumber('notice');
        Route::get('/notices/{notice}/items', [NoticeController::class, 'items'])->whereNumber('notice');
        Route::post('/notices/{notice}/skip', [NoticeController::class, 'skip']);
        Route::delete('/notices/{notice}/skip', [NoticeController::class, 'unskip']);
    });
    Route::post('/notices/{notice}/tender', [NoticeController::class, 'createTender'])->middleware(['permission:notices.view', 'permission:tenders.create']);

    Route::get('/exchange-rates', ExchangeRateController::class)->middleware('permission:products.view');
    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.view');
    // Zapasy: towary ERP XL ze stanem bez sprzedaży od N miesięcy (tylko odczyt kopii XL)
    // handlowiec widzi listę zalegających towarów, żeby robić z nich kampanie (decyzja właściciela 30.09.2026)
    Route::get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view|campaigns.use');
    Route::get('/inventory/rw-pw', [InventoryRwPwController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/inventory/board', [InventoryBoardController::class, 'show'])->middleware('permission:inventory.report.view');
    Route::get('/inventory/board/items', [InventoryBoardController::class, 'items'])->middleware('permission:inventory.report.view');
    Route::get('/inventory/board/moves', [InventoryBoardController::class, 'moves'])->middleware('permission:inventory.report.view');
    Route::get('/inventory/board/history', [InventoryBoardController::class, 'history'])->middleware('permission:inventory.report.view');
    Route::get('/products/manufacturers', [ProductController::class, 'manufacturers'])->middleware('permission:products.view');
    Route::get('/products/word-counts', [ProductController::class, 'wordCounts'])->middleware('permission:products.view');
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
    // karty usunięte z pominięciem przy imporcie — podgląd i przywracanie pozycji (Cenniki → Usunięte z pominięciem)
    Route::get('/import-exclusions', [ImportExclusionController::class, 'index'])->middleware('permission:products.delete');
    Route::post('/import-exclusions/restore', [ImportExclusionController::class, 'restore'])->middleware('permission:products.delete');
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
    // ekran zamienników: liczby do filtrów i lista pogrupowana po karcie głównej (przed /substitutes/{productSubstitute})
    Route::get('/substitutes/summary', [ProductSubstituteController::class, 'summary'])->middleware('permission:products.view');
    Route::get('/substitutes/board', [ProductSubstituteController::class, 'board'])->middleware('permission:products.view');
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
    // ręczne łączenie zaznaczonych kart z listy produktów (podgląd i połączenie)
    Route::post('/card-matches/manual/preview', [CardMatchController::class, 'manualPreview'])->middleware('permission:card_matches.decide');
    Route::post('/card-matches/manual', [CardMatchController::class, 'manualMerge'])->middleware('permission:card_matches.decide');
    Route::post('/card-matches/{candidate}/merge', [CardMatchController::class, 'merge'])
        ->whereNumber('candidate')
        ->middleware('permission:card_matches.decide');
    Route::post('/card-matches/{candidate}/merge-sizes', [CardMatchController::class, 'mergeSizes'])
        ->whereNumber('candidate')
        ->middleware('permission:card_matches.decide');
    Route::post('/card-matches/{candidate}/split', [CardMatchController::class, 'split'])
        ->whereNumber('candidate')
        ->middleware('permission:card_matches.decide');
    Route::post('/card-matches/{candidate}/reject', [CardMatchController::class, 'reject'])
        ->whereNumber('candidate')
        ->middleware('permission:card_matches.decide');

    Route::get('/clients', [ClientController::class, 'index'])->middleware('permission:clients.view');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('permission:clients.manage');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.manage');
    // karta klienta: dane, oś czasu i notatki (edycja i usuwanie notatki — autor albo clients.manage, w kontrolerze)
    Route::middleware('permission:clients.view')->group(function (): void {
        Route::get('/clients/{client}', [ClientCardController::class, 'show'])->whereNumber('client');
        Route::get('/clients/{client}/timeline', [ClientCardController::class, 'timeline'])->whereNumber('client');
        Route::post('/clients/{client}/notes', [ClientNoteController::class, 'store'])->whereNumber('client');
        Route::patch('/clients/{client}/notes/{note}', [ClientNoteController::class, 'update'])
            ->whereNumber(['client', 'note'])->scopeBindings();
        Route::delete('/clients/{client}/notes/{note}', [ClientNoteController::class, 'destroy'])
            ->whereNumber(['client', 'note'])->scopeBindings();
    });

    Route::middleware('permission:inquiries.use')->group(function (): void {
        Route::get('/inquiries', [ClientInquiryController::class, 'index']);
        Route::post('/inquiries', [ClientInquiryController::class, 'store']);
        // przed „{inquiry}”, żeby stałe ścieżki nie zostały wzięte za numer zapytania
        Route::get('/inquiries/preferences', [ClientInquiryController::class, 'preferences']);
        Route::get('/inquiries/queued', [ClientInquiryController::class, 'queued']);
        // dodatek do Thunderbirda: które maile mają już zapytanie i kto je prowadzi
        Route::post('/inquiries/lookup', [ClientInquiryController::class, 'lookup']);
        Route::get('/inquiries/message-ids', [ClientInquiryController::class, 'messageIds']);
        // tekst zapytania z pliku klienta (Excel, PDF, Word) — do pola treści, bez zakładania zapytania
        Route::post('/inquiries/file-text', [ClientInquiryController::class, 'fileText']);
        Route::get('/inquiries/{inquiry}', [ClientInquiryController::class, 'show']);
        Route::patch('/inquiries/{inquiry}', [ClientInquiryController::class, 'update']);
        Route::get('/inquiries/{inquiry}/reply-preview', [ClientInquiryController::class, 'replyPreview']);
        Route::post('/inquiries/{inquiry}/compose', [ClientInquiryController::class, 'compose']);
        // ponowna analiza w tle po błędzie albo przerwanym przebiegu
        Route::post('/inquiries/{inquiry}/retry-analysis', [ClientInquiryController::class, 'retryAnalysis']);
        Route::post('/inquiries/{inquiry}/pick-product', [ClientInquiryController::class, 'pickProduct']);
        Route::post('/inquiries/{inquiry}/replied', [ClientInquiryController::class, 'replied']);
        Route::post('/inquiries/{inquiry}/queue-reply', [ClientInquiryController::class, 'queueReply']);
        // jak się skończyło zapytanie i powiązanie z klientem — tylko autor zapytania (sprawdzenie w kontrolerze)
        Route::put('/inquiries/{inquiry}/outcome', [InquiryOutcomeController::class, 'update']);
        Route::put('/inquiries/{inquiry}/client', [InquiryOutcomeController::class, 'client']);
        // kasuje tylko autor zapytania — sprawdzenie w kontrolerze
        Route::delete('/inquiries/{inquiry}', [ClientInquiryController::class, 'destroy']);

        // oferta dla klienta z karty → nowy mail w Thunderbirdzie (dodatek podejmuje prośbę przy pytaniu o kolejkę)
        Route::get('/offers/compose/status', [OfferComposeController::class, 'status']);
        Route::post('/offers/compose', [OfferComposeController::class, 'store']);
        Route::get('/offers/compose/{offerCompose}', [OfferComposeController::class, 'show'])->whereNumber('offerCompose');
        Route::post('/offers/compose/{offerCompose}/claim', [OfferComposeController::class, 'claim'])->whereNumber('offerCompose');
        // PDF oferty z modułu Ofert, który dodatek dołącza do nowego maila (prośba z attach_pdf)
        Route::get('/offers/compose/{offerCompose}/pdf', [OfferComposeController::class, 'pdf'])->whereNumber('offerCompose')->middleware('throttle:30,1');
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
    // „Scal rozmiary” — karty rozbite dawniej według ceny rozmiaru (zadanie w tle, B2bSizePriceMerger)
    Route::get('/b2b-accounts/{b2bAccount}/size-merge', [B2bAccountController::class, 'sizeMerge'])
        ->middleware('permission:b2b_accounts.view');
    Route::post('/b2b-accounts/{b2bAccount}/size-merge', [B2bAccountController::class, 'startSizeMerge'])
        ->middleware('permission:b2b_accounts.manage');
    // „Uzupełnij krótkie opisy” — karty z krótkim opisem z B2B szukane najpierw na stronach z opisami konta
    Route::post('/b2b-accounts/{b2bAccount}/supplement-descriptions', [B2bAccountController::class, 'supplementDescriptions'])
        ->middleware('permission:b2b_accounts.manage');
    // okno postępu uzupełniania opisów i „Zatrzymaj” / „Zatrzymaj wszystko”
    Route::get('/b2b-accounts/{b2bAccount}/supplement-progress', [B2bAccountController::class, 'supplementProgress'])
        ->middleware('permission:b2b_accounts.view');
    Route::post('/b2b-accounts/{b2bAccount}/supplement-stop', [B2bAccountController::class, 'stopSupplement'])
        ->middleware('permission:b2b_accounts.manage');
    Route::post('/b2b-accounts/supplement-stop-all', [B2bAccountController::class, 'stopAllSupplements'])
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

    // podgląd (campaigns.view): cudze kampanie po starcie wysyłki, test tylko na własny adres — sprawdza kontroler
    Route::middleware('permission:campaigns.use|campaigns.view')->group(function (): void {
        Route::get('/campaigns', [CampaignController::class, 'index']);
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
        Route::get('/campaigns/{campaign}/preview', [CampaignController::class, 'preview']);
        Route::post('/campaigns/{campaign}/test', [CampaignController::class, 'test'])->middleware('throttle:10,1');
        Route::get('/campaigns/{campaign}/recipients', [CampaignController::class, 'recipients']);
    });

    Route::middleware('permission:campaigns.use')->group(function (): void {
        Route::post('/campaigns', [CampaignController::class, 'store']);
        Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update']);
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
        Route::post('/campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate']);
        Route::post('/campaigns/{campaign}/items', [CampaignController::class, 'addItems']);
        Route::patch('/campaigns/{campaign}/items/{item}', [CampaignController::class, 'updateItem']);
        Route::delete('/campaigns/{campaign}/items/{item}', [CampaignController::class, 'removeItem']);
        Route::get('/campaigns/{campaign}/audience', [CampaignController::class, 'audience']);
        Route::get('/campaigns/{campaign}/audience/recipients', [CampaignController::class, 'audienceRecipients']);
        Route::get('/campaigns/{campaign}/xl-customers', [CampaignController::class, 'xlCustomers']);
        Route::get('/campaigns/{campaign}/list-contacts', [CampaignController::class, 'listContacts']);
        Route::post('/campaigns/{campaign}/send', [CampaignController::class, 'send']);
        Route::post('/campaigns/{campaign}/schedule', [CampaignController::class, 'schedule']);
        Route::post('/campaigns/{campaign}/unschedule', [CampaignController::class, 'unschedule']);
        Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
        Route::post('/campaigns/{campaign}/recipients/add', [CampaignController::class, 'addRecipients']);
        Route::get('/campaigns/{campaign}/suggestions', [CampaignController::class, 'suggestions']);
        Route::post('/campaigns/{campaign}/replies/check', [CampaignController::class, 'checkReplies'])->middleware('throttle:6,1');
        Route::post('/campaigns/{campaign}/template', [CampaignController::class, 'applyTemplate']);
        Route::post('/campaigns/{campaign}/preview-draft', [CampaignController::class, 'previewDraft']);

        Route::get('/campaign-templates', [CampaignTemplateController::class, 'index']);
        Route::post('/campaign-templates', [CampaignTemplateController::class, 'store']);
        Route::post('/campaign-templates/preview', [CampaignTemplateController::class, 'preview']);
        Route::patch('/campaign-templates/{template}', [CampaignTemplateController::class, 'update']);
        Route::delete('/campaign-templates/{template}', [CampaignTemplateController::class, 'destroy']);
        Route::post('/campaign-assets', [CampaignAssetController::class, 'store'])->middleware('throttle:30,1');

        Route::get('/mailing-lists', [MailingListController::class, 'index']);
        Route::post('/mailing-lists', [MailingListController::class, 'store']);
        Route::patch('/mailing-lists/{list}', [MailingListController::class, 'update']);
        Route::delete('/mailing-lists/{list}', [MailingListController::class, 'destroy']);
        Route::get('/mailing-lists/{list}/contacts', [MailingListController::class, 'contacts']);
        Route::post('/mailing-lists/{list}/import', [MailingListController::class, 'import']);
        Route::post('/mailing-lists/{list}/import-file/preview', [MailingListController::class, 'importFilePreview'])->middleware('throttle:30,1');
        Route::post('/mailing-lists/{list}/import-file', [MailingListController::class, 'importFile'])->middleware('throttle:30,1');
        Route::delete('/mailing-lists/{list}/contacts/{contact}', [MailingListController::class, 'removeContact']);

        Route::get('/email-suppressions', [EmailSuppressionController::class, 'index']);
        Route::post('/email-suppressions', [EmailSuppressionController::class, 'store']);
        Route::delete('/email-suppressions/{suppression}', [EmailSuppressionController::class, 'destroy']);
    });

    // „Moja poczta” — skrzynka do wysyłki kampanii i ofert
    Route::middleware('permission:campaigns.use|offers.use')->group(function (): void {
        Route::get('/me/mail-account', [UserMailAccountController::class, 'show']);
        Route::put('/me/mail-account', [UserMailAccountController::class, 'update']);
        Route::post('/me/mail-account/test', [UserMailAccountController::class, 'test'])->middleware('throttle:10,1');
    });

    // oferty dla klientów — tylko własne (cudza = 404, sprawdza kontroler); whereNumber: /offers/compose/* (zapytania
    // klientów, wyżej) nie trafia tutaj
    Route::middleware('permission:offers.use')->group(function (): void {
        Route::get('/offers', [OfferController::class, 'index']);
        Route::post('/offers', [OfferController::class, 'store']);
        Route::get('/offers/{offer}', [OfferController::class, 'show'])->whereNumber('offer');
        Route::patch('/offers/{offer}', [OfferController::class, 'update'])->whereNumber('offer');
        Route::delete('/offers/{offer}', [OfferController::class, 'destroy'])->whereNumber('offer');
        Route::post('/offers/{offer}/items', [OfferController::class, 'addItems'])->whereNumber('offer');
        Route::patch('/offers/{offer}/items/{item}', [OfferController::class, 'updateItem'])->whereNumber(['offer', 'item']);
        Route::delete('/offers/{offer}/items/{item}', [OfferController::class, 'removeItem'])->whereNumber(['offer', 'item']);
        Route::get('/offers/{offer}/preview', [OfferController::class, 'preview'])->whereNumber('offer');
        Route::post('/offers/{offer}/copied', [OfferController::class, 'copied'])->whereNumber('offer');
        Route::post('/offers/{offer}/send', [OfferController::class, 'send'])->whereNumber('offer')->middleware('throttle:5,1');
        Route::get('/offers/{offer}/sends/{send}', [OfferController::class, 'showSend'])->whereNumber(['offer', 'send']);
        // PDF bieżącej oferty i PDF zapisany przy wysyłce (forma „pdf”/„both”); składanie PDF kosztuje — limit
        Route::get('/offers/{offer}/pdf', [OfferController::class, 'pdf'])->whereNumber('offer')->middleware('throttle:30,1');
        Route::get('/offers/{offer}/sends/{send}/pdf', [OfferController::class, 'sendPdf'])->whereNumber(['offer', 'send'])->middleware('throttle:30,1');
    });

    Route::middleware('permission:admin.access')->prefix('admin')->group(function (): void {
        Route::get('/erp-operators', [ErpOperatorController::class, 'index'])->middleware('permission:admin.users.manage');
        // pracownicy ERP XL (opiekunowie klientów) do przypisania kontom — cele handlowców
        Route::get('/erp-employees', [ErpEmployeeController::class, 'index'])->middleware('permission:admin.users.manage');
        Route::get('/users', [AdminUserController::class, 'index'])->middleware('permission:admin.users.manage');
        Route::post('/users', [AdminUserController::class, 'store'])->middleware('permission:admin.users.manage');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])->middleware('permission:admin.users.manage');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->middleware('permission:admin.users.manage');
        Route::post('/users/{user}/send-credentials', [AdminUserController::class, 'sendCredentials'])->middleware('permission:admin.users.manage');
        // adresy sieci lokalnej — dla kont i grup „tylko z sieci lokalnej”
        Route::get('/local-networks', [AdminLocalNetworkController::class, 'index'])->middleware('permission:admin.roles.manage');
        Route::put('/local-networks', [AdminLocalNetworkController::class, 'update'])->middleware('permission:admin.roles.manage');

        Route::get('/roles', [AdminRoleController::class, 'index'])->middleware('permission:admin.roles.manage');
        Route::post('/roles', [AdminRoleController::class, 'store'])->middleware('permission:admin.roles.manage');
        Route::put('/roles/{role}', [AdminRoleController::class, 'update'])->middleware('permission:admin.roles.manage');
        Route::patch('/roles/{role}', [AdminRoleController::class, 'rename'])->middleware('permission:admin.roles.manage');
        Route::patch('/roles/{role}/network-access', [AdminRoleController::class, 'updateNetworkAccess'])->middleware('permission:admin.roles.manage');
        Route::delete('/roles/{role}', [AdminRoleController::class, 'destroy'])->middleware('permission:admin.roles.manage');
        // zespoły (kto komu podlega) — raport „Wynik kampanii”
        Route::get('/teams', [AdminTeamController::class, 'index'])->middleware('permission:admin.roles.manage');
        Route::post('/teams', [AdminTeamController::class, 'store'])->middleware('permission:admin.roles.manage');
        Route::put('/teams/{team}', [AdminTeamController::class, 'update'])->middleware('permission:admin.roles.manage');
        Route::delete('/teams/{team}', [AdminTeamController::class, 'destroy'])->middleware('permission:admin.roles.manage');

        Route::get('/activity-logs', [AdminActivityLogController::class, 'index'])
            ->middleware('permission:admin.activity.view');
        Route::get('/sessions', [AdminSessionController::class, 'index'])->middleware('permission:admin.sessions.view');
        Route::get('/users-activity', [AdminSessionController::class, 'users'])->middleware('permission:admin.sessions.view');
        Route::delete('/sessions/stale', [AdminSessionController::class, 'destroyStale'])->middleware('permission:admin.sessions.manage');

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

        Route::get('/ai-stats', [AdminAiStatsController::class, 'index'])
            ->middleware('permission:admin.ai_stats.view');

        // Stan systemu: przebiegi zadań, alerty, dane do uzupełnienia
        Route::middleware('permission:admin.system.view')->group(function (): void {
            Route::get('/system-status', [AdminSystemStatusController::class, 'show']);
            Route::get('/system-status/gaps/{kind}', [AdminSystemStatusController::class, 'gaps'])
                ->whereIn('kind', AdminSystemStatusController::GAP_KINDS);
            Route::post('/system-alerts/{alert}/mute', [AdminSystemStatusController::class, 'mute'])->whereNumber('alert');
            Route::post('/system-alerts/{alert}/unmute', [AdminSystemStatusController::class, 'unmute'])->whereNumber('alert');
        });

        // Powiązania towarów Comarch ERP XL z kartami
        Route::get('/erp-items', [ErpItemController::class, 'index'])->middleware('permission:admin.erp_links.view');
        Route::get('/erp-items/summary', [ErpItemController::class, 'summary'])->middleware('permission:admin.erp_links.view');
        Route::get('/erp-items/export', [ErpItemController::class, 'export'])->middleware(['permission:admin.erp_links.view', 'permission:admin.erp_links.export']);
        Route::middleware('permission:admin.erp_links.manage')->group(function (): void {
            Route::post('/erp-items/{item}/link', [ErpItemController::class, 'link']);
            Route::post('/erp-links/bulk-confirm', [ErpItemController::class, 'bulkConfirm']);
            Route::post('/erp-links/{link}/confirm', [ErpItemController::class, 'confirm']);
            Route::post('/erp-links/{link}/reject', [ErpItemController::class, 'reject']);
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
