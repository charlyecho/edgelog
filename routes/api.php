<?php

use App\Http\Controllers\IngestController;
use App\Http\Controllers\StripeHookController;
use Illuminate\Support\Facades\Route;

Route::post('/ingest/{site}', IngestController::class)->name('ingest.store');
Route::post('/ingest/{site}/history', [IngestController::class, 'history'])->name('ingest.history');
Route::post('/ingest/{site}/metrics', [IngestController::class, 'metrics'])->name('ingest.metrics');
Route::post('/ingest/{site}/errors/{kind}', [IngestController::class, 'errors'])->name('ingest.errors');
Route::post('/hooks/{site}/stripe', StripeHookController::class)->name('hooks.stripe');
