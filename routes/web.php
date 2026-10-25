<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('wallet.index'))->name('home');
Route::get('/dashboard', fn () => redirect()->route('wallet.index'))->middleware('auth')->name('dashboard');
Route::get('/admin/wallet/balances.csv', [WalletController::class, 'balances'])->middleware('auth')->name('wallet.balances');
Route::middleware('auth')->group(function () {
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/deposit', [WalletController::class, 'deposit'])->middleware('throttle:10,1')->name('wallet.deposit');
    Route::post('/wallet/withdraw', [WalletController::class, 'withdraw'])->middleware('throttle:10,1')->name('wallet.withdraw');
    Route::post('/wallet/transfer', [WalletController::class, 'transfer'])->middleware('throttle:10,1')->name('wallet.transfer');
    Route::get('/admin/wallet', [WalletController::class, 'admin'])->name('wallet.admin');
    Route::get('/admin/wallet/report.csv', [WalletController::class, 'report'])->name('wallet.report');
    Route::post('/admin/wallet/transactions/{transaction}/review', [WalletController::class, 'review'])->name('wallet.review');
    Route::put('/admin/wallets/{wallet}/limits', [WalletController::class, 'updateLimit'])->name('wallet.limits.update');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
require __DIR__.'/auth.php';
