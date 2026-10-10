<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home')->name('root');

require __DIR__.'/briefs.php';
require __DIR__.'/auth.php';
require __DIR__.'/pwa.php';
