<?php

/*
 * openai-php/laravel reads this file. This is the single env() source for OPENAI_*.
 * config/services.php mirrors these keys under services.openai.* (loaded after this file).
 */
return [

    'api_key' => ($k = env('OPENAI_API_KEY')) !== null && trim((string) $k) !== '' ? trim((string) $k) : null,
    'organization' => ($o = env('OPENAI_ORGANIZATION')) !== null && trim((string) $o) !== '' ? trim((string) $o) : null,
    'project' => ($p = env('OPENAI_PROJECT')) !== null && trim((string) $p) !== '' ? trim((string) $p) : null,
    'base_uri' => ($b = env('OPENAI_BASE_URL')) !== null && trim((string) $b) !== '' ? trim((string) $b) : null,
    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 30),

];
