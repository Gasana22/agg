<?php

namespace App\Modules\Media;

use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::model('media', Media::class);
    }
}
