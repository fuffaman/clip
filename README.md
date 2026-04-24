# Shared Clipboard

A simple PHP and SQLite based shared clipboard.

## Nginx Configuration

If you are using Nginx, you can use the following configuration to handle the single-page application routing.

### Root Installation

If the app is installed at the root of your domain:

```nginx
location / {
    try_files $uri $uri/ /index.html;
}

location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
}
```

### Subdirectory Installation

If the app is installed in a subdirectory (e.g., `/clipboard/`):

```nginx
location /clipboard/ {
    try_files $uri $uri/ /clipboard/index.html;
}

location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
}
```

Make sure to adjust the `fastcgi_pass` path to match your PHP-FPM socket location.
