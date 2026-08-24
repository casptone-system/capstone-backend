<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available for use when storing files.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Evidence disk (documents + role-storage)
    |--------------------------------------------------------------------------
    |
    | Accreditation evidence and role-vault files use this disk. Keep
    | FILESYSTEM_DISK=local so profile photos (`public`) and Dean/instrument
    | files (`private`) are unaffected. Set EVIDENCE_DISK=s3 to write those
    | objects to Cloudflare R2.
    |
    */

    'evidence_disk' => env('EVIDENCE_DISK', 'local'),

    'document_upload_max_kb' => (int) env('DOCUMENT_UPLOAD_MAX_KB', 51200),

    'media_upload_max_kb' => (int) env('MEDIA_UPLOAD_MAX_KB', 1048576),

    'chunk_size_bytes' => (int) env('UPLOAD_CHUNK_SIZE_BYTES', 8 * 1024 * 1024),

    'chunk_threshold_bytes' => (int) env('UPLOAD_CHUNK_THRESHOLD_BYTES', 50 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
            'report' => false,
        ],

        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT') ?: (env('R2_ACCOUNT_ID')
                ? 'https://'.env('R2_ACCOUNT_ID').'.r2.cloudflarestorage.com'
                : null),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
