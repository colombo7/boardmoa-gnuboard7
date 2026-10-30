<?php

use Illuminate\Support\Facades\Route;
use Plugins\Easyapplabs\Boardmoa\Api;

// 앱 규약 입구 하나: /api/plugins/easyapplabs-boardmoa/api?r=<route> (GET·POST, 인증은 Api 가 Bearer 로 본다)
Route::match(['GET', 'HEAD', 'POST'], 'api', [Api::class, 'handle'])->name('api');
