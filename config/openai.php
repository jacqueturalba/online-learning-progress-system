<?php

return [
    'whisper_http_timeout' => max(1, (int) env('OPENAI_WHISPER_HTTP_TIMEOUT', 1800)),
    'whisper_connect_timeout' => max(1, (int) env('OPENAI_WHISPER_CONNECT_TIMEOUT', 30)),
];
