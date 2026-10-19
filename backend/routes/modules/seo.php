<?php

use App\Modules\Seo\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

// Local-SEO toolkit (keyword tracking, rank checking, competitor analysis).
// Gated by the plan feature 'seo'.
Route::prefix('seo')->middleware('feature:seo')->group(function () {
    Route::get('/overview', [SeoController::class, 'overview']);

    // Keyword tracking + rank checking.
    Route::get('/keywords', [SeoController::class, 'keywords']);
    Route::post('/keywords', [SeoController::class, 'storeKeyword']);
    Route::post('/keywords/generate', [SeoController::class, 'generate']);
    Route::post('/keywords/{uuid}/check', [SeoController::class, 'checkKeyword']);
    Route::get('/keywords/{uuid}/history', [SeoController::class, 'history']);
    Route::delete('/keywords/{uuid}', [SeoController::class, 'deleteKeyword']);

    // Competitor analysis.
    Route::get('/competitors', [SeoController::class, 'competitors']);
    Route::post('/competitors', [SeoController::class, 'storeCompetitor']);
    Route::post('/competitors/{uuid}/analyze', [SeoController::class, 'analyze']);
    Route::delete('/competitors/{uuid}', [SeoController::class, 'deleteCompetitor']);
});
