<?php

/*
 * The reverse proxies whose X-Forwarded-* headers are believed (D-054). Laravel's TrustProxies middleware, already in
 * the global stack, reads this when nothing else is set.
 *
 * Behind a proxy every request comes from the proxy's address: without this, the login throttles (per address) are
 * shared by everybody and the audit log and the failed-login lines record the proxy instead of the user.
 *
 * TRUSTED_PROXIES: the proxy's addresses or ranges, comma separated (e.g. "172.16.0.0/12"), or "*" for whatever
 * address is calling, which is only safe when the application's port is reachable by the proxy alone. Unset: no proxy
 * is trusted, and the headers are ignored.
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
