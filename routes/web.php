<?php

use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\FileManagerController;
use App\Http\Middleware\EnsureSftpConnected;
use Illuminate\Support\Facades\Route;

Route::get('/', [ConnectionController::class, 'index'])->name('home');
Route::post('/connect', [ConnectionController::class, 'connect'])->middleware('throttle:10,1')->name('connect');
Route::post('/disconnect', [ConnectionController::class, 'disconnect'])->name('disconnect');

// Transfer log: session only, no SFTP connection needed (cheap to poll).
Route::get('/api/log', [FileManagerController::class, 'log'])->name('api.log');
Route::post('/api/log/clear', [FileManagerController::class, 'clearLog'])->name('api.log.clear');

// The page itself needs no SFTP connection (the listing is fetched by the UI), so it skips the middleware.
Route::get('/files', [FileManagerController::class, 'index'])->name('files');

Route::middleware(EnsureSftpConnected::class)->group(function () {

    Route::prefix('api')->name('api.')->controller(FileManagerController::class)->group(function () {
        Route::get('/list', 'list')->name('list');
        Route::get('/stat', 'stat')->name('stat');
        Route::get('/search', 'search')->name('search');
        Route::post('/tree', 'tree')->name('tree');
        Route::post('/hashes', 'hashes')->name('hashes');
        Route::get('/read', 'read')->name('read');
        Route::get('/download', 'download')->name('download');
        Route::post('/write', 'write')->name('write');
        Route::post('/mkdir', 'createDirectory')->name('mkdir');
        Route::post('/touch', 'createFile')->name('touch');
        Route::post('/rename', 'rename')->name('rename');
        Route::post('/delete', 'delete')->name('delete');
        Route::post('/paste', 'paste')->name('paste');
        Route::post('/upload', 'upload')->name('upload');
        Route::post('/upload/abort', 'abortUpload')->name('upload.abort');
        Route::get('/upload/status', 'uploadStatus')->name('upload.status');
    });
});
