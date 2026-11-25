# Catch all unknown hosts before the project virtual host.
<VirtualHost *:80>
    ServerName denied.invalid
    <Location />
        Require all denied
    </Location>
</VirtualHost>

<VirtualHost *:80>
    ServerName localhost
    ServerAlias ${CDN_HOST_PATTERN}
    UseCanonicalName Off
    VirtualDocumentRoot /target/%1
    DirectoryIndex index.html

    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined

    <Directory /target>
        AllowOverride None
        Options -Indexes -ExecCGI -Includes -FollowSymLinks +MultiViews
        Require method GET HEAD
    </Directory>

<?php
$reloadUrl = getenv('PREVIEW_RELOAD_JS_URL');
if ($reloadUrl !== false && $reloadUrl !== '') {
    // Only accept an absolute HTTP(S) URL safe for the Apache substitution.
    if (
        !preg_match('~\Ahttps?://[A-Za-z0-9._:/?#\[\]@!$&\'()*+,;=%-]+\z~i', $reloadUrl)
        || !parse_url($reloadUrl, PHP_URL_HOST)
    ) {
        throw new RuntimeException('PREVIEW_RELOAD_JS_URL must be an absolute HTTP(S) URL');
    }
    echo "    AddOutputFilterByType SUBSTITUTE text/html\n";
    echo '    Substitute "s|</body>|<script src=\"'
        . htmlspecialchars($reloadUrl, ENT_QUOTES, 'UTF-8')
        . '\"></script></body>|ni"' . "\n";
}
?>

    # Internal publishing directories and hidden files are never served.
    <DirectoryMatch "(^|/)\.">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    <LocationMatch "(^|/)\.">
        Require all denied
    </LocationMatch>

    Header always set X-Content-Type-Options nosniff
    Header always unset X-Powered-By

    RewriteEngine On
    RewriteCond %{HTTP_HOST} !^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+(?::[0-9]{1,5})?$ [NC]
    RewriteRule ^ - [F]
</VirtualHost>
