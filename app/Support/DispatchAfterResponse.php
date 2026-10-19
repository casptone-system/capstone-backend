<?php

namespace App\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Free-tier friendly dispatch: wait for the DB commit, then run after the HTTP
 * response so sync queues (Render free) and database queues (Railway worker)
 * both keep the request fast.
 */
class DispatchAfterResponse
{
    public static function job(ShouldQueue $job): void
    {
        $send = static function () use ($job): void {
            // Unit tests often call services without an HTTP terminate cycle.
            if (app()->runningUnitTests()) {
                dispatch($job);

                return;
            }

            dispatch($job)->afterResponse();
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);

            return;
        }

        $send();
    }
}
