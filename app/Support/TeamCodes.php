<?php

namespace App\Support;

use App\Models\Program;
use App\Models\Team;
use App\Models\TeamJoinCode;

final class TeamCodes
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    public static function unique(): string
    {
        do {
            $code = self::generate();
        } while (
            Team::query()->where('code', $code)->exists()
            || TeamJoinCode::query()->where('code', $code)->exists()
            || Program::query()->where('code', $code)->exists()
        );

        return $code;
    }
}
