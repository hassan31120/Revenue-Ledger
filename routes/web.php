<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * This application has no public web surface. Everything it does runs through
 * console commands and queued jobs; the only screen is the read-only admin panel.
 */
Route::redirect('/', '/admin');
