<?php

function httpGet(string $url): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/plain,*/*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari'
        ]
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'http_code' => $code,
            'error' => "Erreur cURL : {$err}",
            'raw' => null,
            'json' => null
        ];
    }

    $decoded = json_decode($body, true);

    return [
        'ok' => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'error' => ($code >= 200 && $code < 300) ? null : "HTTP {$code}",
        'raw' => $body,
        'json' => $decoded
    ];
}
