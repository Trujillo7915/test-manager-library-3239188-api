<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Manager Library · SDD');
})->purpose('Display an inspiring quote');
