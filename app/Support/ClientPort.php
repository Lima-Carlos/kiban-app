<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The visitor's source port (Marco Civil, art. 15 and Decree 8.771: with shared IPs (CGNAT) the IP alone does not
 * identify who accessed). Behind the server's proxy (Caddy/Nginx) the real port comes in the X-Client-Port header,
 * accepted only from a trusted proxy (TRUSTED_PROXIES), so nobody can fake it; without a proxy, the connection's own.
 */
class ClientPort
{
    public static function of(?Request $request = null): ?int
    {
        $request ??= request();
        $header = $request->headers->get('X-Client-Port');

        if ($header !== null && $request->isFromTrustedProxy() && ctype_digit($header)) {
            return (int) $header;
        }

        $port = $request->server('REMOTE_PORT');

        return is_numeric($port) ? (int) $port : null;
    }
}
