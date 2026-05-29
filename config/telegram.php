<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram outbound proxy
    |--------------------------------------------------------------------------
    |
    | Outbound HTTPS to api.telegram.org from the prod server is filtered
    | intermittently (RKN). When `proxy_enabled` is true and `proxy_url` is
    | set, all HTTPS requests made through Laravel's Http client (including
    | every Telegraph send()) are routed through that proxy.
    |
    | `proxy_url` is a full SOCKS or HTTP URL with credentials, e.g.
    |     socks5h://user:pass@host:port
    |     http://user:pass@host:port
    |
    | `proxy_fallback_direct` — if true, the TelegraphRetry helper will use
    | the proxy for the first attempts and switch to a direct connection for
    | the last two attempts. Saves us when the proxy itself is down.
    |
    | `proxy_no_hosts` — comma-separated list of hostnames/IPs that should
    | bypass the proxy even when it's enabled. The VPN API host is HTTP, not
    | HTTPS, so it bypasses anyway — list things here only if they also use
    | HTTPS and you don't want them proxied.
    |
    */

    'proxy_enabled' => filter_var(env('TELEGRAM_PROXY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'proxy_url' => env('TELEGRAM_PROXY_URL'),

    'proxy_fallback_direct' => filter_var(env('TELEGRAM_PROXY_FALLBACK_DIRECT', true), FILTER_VALIDATE_BOOLEAN),

    'proxy_no_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TELEGRAM_PROXY_NO_HOSTS', ''))
    ))),

];
