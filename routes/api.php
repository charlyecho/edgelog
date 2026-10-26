<?php

use App\Http\Controllers\IngestController;
use App\Http\Controllers\StripeHookController;
use Illuminate\Support\Facades\Route;

Route::post('/ingest/{site}', IngestController::class)->name('ingest.store');
Route::post('/ingest/{site}/history', [IngestController::class, 'history'])->name('ingest.history');
Route::post('/hooks/{site}/stripe', StripeHookController::class)->name('hooks.stripe');
