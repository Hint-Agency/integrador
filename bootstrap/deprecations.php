<?php

if (PHP_VERSION_ID >= 80500) {
    set_error_handler(static function (int $severity, string $message, string $file): bool {
        if (
            $severity === E_DEPRECATED
            && str_contains($message, 'PDO::MYSQL_ATTR_SSL_CA')
            && str_ends_with($file, 'vendor/laravel/framework/config/database.php')
        ) {
            return true;
        }

        return false;
    });
}
