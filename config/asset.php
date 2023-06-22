<?php

return [
    'recaptcha' => [
        'secret' => env('RECAPTCHA_SECRET'),
    ],

    'max_upload_document_size' => env('MAX_UPLOAD_DOCUMENT_SIZE', 10),

    'storage_disk' => env('ASSET_STORAGE_DISK', 'public'),

    'storage_url' => env('ASSET_STORAGE_URL'),
];
