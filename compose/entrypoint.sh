#!/bin/sh

php-fpm83 -D
nginx -g 'daemon off;'
