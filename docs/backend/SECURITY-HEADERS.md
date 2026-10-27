# Browser security headers

Seat Manager has no embedded-frame mode. Serve the application, installer and API
with these response headers:

```http
Content-Security-Policy: frame-ancestors 'none'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
```

The CSP adds only framing protection. It does not introduce a new restrictive
script/style policy or break the existing interface's inline style attributes.
The installer retains its separate existing CSP. `frame-ancestors` must be sent
as an HTTP response header; placing it in an HTML meta element does not work.

## Apache and compatible LiteSpeed

The prepared release includes `public_html/.htaccess`, including `DirectoryIndex
index.html`. Apache 2.4 with `mod_headers` and `AllowOverride FileInfo` applies the
policy to static HTML and PHP, including error responses. The `IfModule` guard
keeps hosts without that module from failing, but such a host needs the headers
configured by its administrator instead. A host forbidding `Header` directives
must use virtual-host configuration rather than this block.

The PHP API also sends the same policy before application bootstrap, so an
unavailable database/configuration does not remove protection from its error
responses. PHP cannot set headers on HTML served directly by a web server.

## HSTS rollout

On real HTTPS responses the initial policy is:

```http
Strict-Transport-Security: max-age=86400
```

This is a one-day first rollout for this hostname. It does not include
`includeSubDomains` or `preload`. Confirm certificate renewal and HTTPS remain
working before increasing the duration. HSTS is deliberately absent on ordinary
local HTTP; the PHP fallback and Apache condition do not trust
`X-Forwarded-Proto`, `Forwarded`, a port number or the request Host as evidence of
TLS. A reverse proxy must set the server's trusted HTTPS environment correctly
and configure HSTS at the TLS terminator. Do not trust arbitrary client headers
to turn HTTPS on.

HSTS affects future visits and cannot replace a safe initial HTTP-to-HTTPS
redirect. Configure that redirect for the fixed, intended hostname in the
hosting panel/virtual host after HTTPS is ready. The distributed application
does not construct redirects from a client-provided Host header, and does not
force local HTTP development onto TLS.

## Nginx or a proxy serving static files

Nginx ignores `.htaccess`; a reverse proxy may serve `index.html`, `install.html`
and assets without reaching Apache/PHP. Apply the policy in that hostname's
HTTPS `server` block using the hosting panel's supported custom configuration:

```nginx
add_header Content-Security-Policy "frame-ancestors 'none'" always;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "no-referrer" always;
add_header Strict-Transport-Security "max-age=86400" always;
```

Only use the HSTS line in the HTTPS server block. If a child `location` sets any
`add_header`, check header inheritance there as well; older Nginx versions do
not inherit the parent headers in that situation. Avoid duplicate headers from
the proxy and PHP/backend. Use `proxy_hide_header` for headers supplied by an
HTTP upstream or `fastcgi_hide_header` for a FastCGI upstream in the appropriate
configuration, then add the canonical policy at the TLS server. Do not weaken
an existing broader CSP or longer verified HSTS policy when integrating these
defaults.

## Verify after deployment

Check the final public responses, not just the files in the ZIP:

```sh
curl --head https://your-fixed-app-host.example/
curl --head https://your-fixed-app-host.example/install.html
curl --head 'https://your-fixed-app-host.example/api/index.php?route=%2Fhealth'
curl --head https://your-fixed-app-host.example/api/install.php
```

Confirm framing headers on all four paths and exactly one intended HSTS policy
on HTTPS. The API may answer an unsupported HEAD method; its headers must still
be present. Confirm normal rendering/login and the installer status still work.
An HTTP request with `X-Forwarded-Proto: https` alone must not gain an HSTS header
from the application. The package's PHP regression test is
`php tests/backend/security-headers.php`; it uses only synthetic local files and
HTTP, not an installation's database or SMTP.

Primary references:

- [Apache mod_headers](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
- [Apache expression variables](https://httpd.apache.org/docs/2.4/expr.html)
- [Nginx headers module and inheritance](https://nginx.org/en/docs/http/ngx_http_headers_module.html)
- [HSTS behavior](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security)
- [CSP frame-ancestors](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-ancestors)
