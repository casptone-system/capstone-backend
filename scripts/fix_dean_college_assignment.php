<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Str;

function parseArgs(array $argv): array
{
    $options = [];
    foreach ($argv as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
            continue;
        }

        $options[$arg] = true;
    }

    return $options;
}

$options = parseArgs($argv);

function output(string $message): void
{
    echo $message . PHP_EOL;
}

function findDeanUsers(array $options)
{
    if (! empty($options['id'])) {
        return User::role('Dean')->where('id', (int) $options['id'])->get();
    }

    if (! empty($options['email'])) {
        return User::role('Dean')->where('email', $options['email'])->get();
    }

    return User::role('Dean')->whereNull('college_id')->get();
}

$outputIntro = true;
if ($outputIntro) {
    output('Dean college assignment repair utility');
    output('Usage: php scripts/fix_dean_college_assignment.php [--id=ID] [--email=EMAIL] [--college_id=ID]');
    output('If --college_id is omitted, the script will infer the college from program_id or team_id.');
    output('');
}

$deans = findDeanUsers($options);
if ($deans->isEmpty()) {
    output('No dean user records found that match the criteria.');
    exit(0);
}

foreach ($deans as $dean) {
    output('Processing Dean user:');
    output('  id: ' . $dean->id);
    output('  email: ' . $dean->email);
    output('  program_id: ' . ($dean->program_id ?? 'null'));
    output('  team_id: ' . ($dean->team_id ?? 'null'));
    output('  college_id: ' . ($dean->college_id ?? 'null'));

    $manualCollegeId = isset($options['college_id']) ? (int) $options['college_id'] : null;
    if ($manualCollegeId) {
        $dean->college_id = $manualCollegeId;
        $dean->save();
        output('  -> Assigned college_id manually: ' . $manualCollegeId);
        continue;
    }

    $effectiveCollegeId = $dean->getEffectiveCollegeId();
    if ($effectiveCollegeId) {
        $dean->college_id = $effectiveCollegeId;
        $dean->save();
        output('  -> Inferred and saved college_id: ' . $effectiveCollegeId);
        continue;
    }

    output('  ! Unable to infer a college for this dean.');
    if (! empty($dean->program_id)) {
        output('    - Program exists but has no associated college.');
    }
    if (! empty($dean->team_id)) {
        output('    - Team exists but its program has no associated college.');
    }
    output('    - Please assign college_id manually or add a valid program/team association.');
}

output('Done.');
