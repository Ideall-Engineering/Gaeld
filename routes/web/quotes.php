<?php

use App\Domains\Invoicing\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

Route::resource('quotes', QuoteController::class);
Route::post('/quotes/{quote}/status', [QuoteController::class, 'status'])->name('quotes.status');
Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convert'])->name('quotes.convert');
Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->middleware('throttle:30,1')->name('quotes.pdf');
