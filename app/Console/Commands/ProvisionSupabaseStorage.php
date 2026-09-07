<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProvisionSupabaseStorage extends Command
{
    protected $signature = 'adams:provision-supabase
        {--token= : Supabase personal access token}
        {--name=adams-storage : Project name}
        {--bucket=accreditation-documents : Private bucket name}';

    protected $description = 'Create a free Supabase project and private evidence bucket';

    public function handle(): int
    {
        $token = (string) ($this->option('token') ?: env('SUPABASE_ACCESS_TOKEN', ''));

        if ($token === '') {
            $this->error('Set SUPABASE_ACCESS_TOKEN or pass --token. Create one at https://supabase.com/dashboard/account/tokens');

            return self::FAILURE;
        }

        $api = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(60);

        $orgs = $api->get('https://api.supabase.com/v1/organizations');

        if ($orgs->unauthorized()) {
            $this->error('Supabase token was rejected. Create a new personal access token and try again.');

            return self::FAILURE;
        }

        if (! $orgs->successful()) {
            $this->error('Unable to list organizations: '.$orgs->body());

            return self::FAILURE;
        }

        $organization = collect($orgs->json())->first(function ($item) {
            return strcasecmp((string) ($item['name'] ?? ''), 'ADAMS') === 0;
        }) ?? $orgs->json()[0] ?? null;

        if (! $organization) {
            $created = $api->post('https://api.supabase.com/v1/organizations', [
                'name' => 'ADAMS',
            ]);

            if (! $created->successful()) {
                $this->error('Unable to create a Supabase organization: '.$created->body());

                return self::FAILURE;
            }

            $organization = $created->json();
        }

        $orgId = $organization['id'] ?? null;
        $this->info('Using organization '.$orgId);

        $projects = $api->get('https://api.supabase.com/v1/projects');

        if (! $projects->successful()) {
            $this->error('Unable to list projects: '.$projects->body());

            return self::FAILURE;
        }

        $project = collect($projects->json())->first(function (array $item) {
            return ($item['name'] ?? '') === $this->option('name');
        });

        if (! $project) {
            $dbPassword = Str::password(24);
            $created = $api->post('https://api.supabase.com/v1/projects', [
                'organization_id' => $orgId,
                'name' => $this->option('name'),
                'db_pass' => $dbPassword,
                'region' => 'ap-southeast-1',
            ]);

            if (! $created->successful()) {
                $this->error('Unable to create project: '.$created->body());

                return self::FAILURE;
            }

            $project = $created->json();
            $this->info('Created project '.$this->option('name').'. Waiting until it is healthy...');
        } else {
            $this->info('Reusing existing project '.$this->option('name'));
        }

        $ref = $project['id'] ?? $project['ref'] ?? null;

        if (! $ref) {
            $this->error('Supabase did not return a project ref.');

            return self::FAILURE;
        }

        $healthy = $this->waitUntilHealthy($api, $ref);

        if (! $healthy) {
            $this->error('Project '.$ref.' did not become healthy in time.');

            return self::FAILURE;
        }

        $keys = $api->get('https://api.supabase.com/v1/projects/'.$ref.'/api-keys');
        $serviceRole = $this->serviceRoleFrom($keys->json());

        if (! $serviceRole) {
            $revealed = $api->get('https://api.supabase.com/v1/projects/'.$ref.'/api-keys', [
                'reveal' => true,
            ]);
            $serviceRole = $this->serviceRoleFrom($revealed->json());
        }

        if (! $serviceRole) {
            $this->error('Could not read the service_role key.');

            return self::FAILURE;
        }

        $url = 'https://'.$ref.'.supabase.co';
        $bucket = (string) $this->option('bucket');
        $storage = Http::withToken($serviceRole)
            ->withHeaders(['apikey' => $serviceRole])
            ->acceptJson()
            ->asJson()
            ->timeout(60);

        $existing = $storage->get($url.'/storage/v1/bucket/'.$bucket);

        if (! $existing->successful()) {
            $createdBucket = $storage->post($url.'/storage/v1/bucket', [
                'id' => $bucket,
                'name' => $bucket,
                'public' => false,
                'file_size_limit' => 50 * 1024 * 1024,
            ]);

            if (! $createdBucket->successful()) {
                $this->error('Unable to create bucket: '.$createdBucket->body());

                return self::FAILURE;
            }

            $this->info('Created private bucket '.$bucket);
        } else {
            $this->info('Bucket '.$bucket.' already exists');
        }

        $envPath = storage_path('app/adams-supabase.env');
        $contents = implode("\n", [
            'EVIDENCE_DISK=supabase',
            'SUPABASE_URL='.$url,
            'SUPABASE_SERVICE_ROLE_KEY='.$serviceRole,
            'AWS_BUCKET='.$bucket,
            'SUPABASE_STORAGE_BUCKET='.$bucket,
        ])."\n";

        file_put_contents($envPath, $contents);
        $this->info('Wrote '.$envPath);

        return self::SUCCESS;
    }

    private function waitUntilHealthy(\Illuminate\Http\Client\PendingRequest $api, string $ref): bool
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $response = $api->get('https://api.supabase.com/v1/projects/'.$ref);
            $status = $response->json('status');

            if (in_array($status, ['ACTIVE_HEALTHY', 'ACTIVE_UNHEALTHY', 'COMING_UP'], true) === false) {
                $this->line('Project status: '.(string) $status);
            } else {
                $this->line('Project status: '.(string) $status);
            }

            if ($status === 'ACTIVE_HEALTHY') {
                return true;
            }

            sleep(10);
        }

        return false;
    }

    /**
     * @param  mixed  $payload
     */
    private function serviceRoleFrom($payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = strtolower((string) ($item['name'] ?? $item['id'] ?? ''));
            $value = $item['api_key'] ?? $item['key'] ?? $item['hash'] ?? null;

            if ($value && (str_contains($name, 'service') || ($item['type'] ?? '') === 'secret')) {
                return (string) $value;
            }
        }

        return null;
    }
}
