<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::namespace ('App\Http\Controllers\Api')->group(function () {
    Route::post('user/login', 'AuthController@login');
});

Route::middleware('auth:api')->namespace('App\Http\Controllers\Api')->group(function () {
    Route::resource('assets', AssetController::class);
    Route::post('assets/{asset}/checkout', 'AssetController@checkout');
    Route::post('assets/{asset}/checkin', 'AssetController@checkin');
    Route::resource('locations', LocationController::class);
    Route::resource('movements', MovementController::class);

    Route::get('reports/datas', 'ReportController@datas');
    Route::get('reports/asset-details', 'ReportController@assetDetails');
    Route::get('reports/asset-details-excel', 'ReportController@assetDetailsExcelExport');
    Route::get('reports/asset-summary', 'ReportController@assetSummary');
    Route::get('reports/locations', 'ReportController@locations');
    Route::get('reports/locationsExcelExport', 'ReportController@locationsExcelExport');

    Route::get('user', 'AuthController@me');
    Route::post('logout', 'AuthController@logout');
});

Route::namespace ('App\Http\Controllers\Api')->group(function () {
    Route::get('test', 'TestController@index');
    Route::get('excel', 'ReportController@locationsExcelExport');
    Route::post('test2', 'TestController@index2');
});
