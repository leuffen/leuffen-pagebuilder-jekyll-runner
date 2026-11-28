#!/usr/bin/env bash

# Kickstart sources this file before exiting; let Nginx finish its shutdown.
if [ -s /run/nginx.pid ]; then
    preview_nginx_pid="$(cat /run/nginx.pid)"
    nginx -s quit
    for preview_nginx_wait in {1..30}; do
        if ! kill -0 "$preview_nginx_pid" 2>/dev/null; then
            break
        fi
        sleep 0.1
    done
fi
