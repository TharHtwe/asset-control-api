<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SetupController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request): \Illuminate\Http\Response
    {
        try {

            Log::debug('Starting: Run database migration');

            // run the migration
            Artisan::call('migrate', [
                '--force' => true,
            ]);

            Log::debug('Finished: Run database migration');

        } catch (\Exception$e) {
            // log the error
            Log::error($e);

            return response('Migration fail', 500);
        }

        try {

            Log::debug('Starting: Passport install');

            // run the migration
            Artisan::call('passport:keys', [
                '--force' => true,
            ]);

            Log::debug('Finished: Passport install');

        } catch (\Exception$e) {
            // log the error
            Log::error($e);

            return response('Passport fail', 500);
        }

        return response('ok', 200);
    }
}
