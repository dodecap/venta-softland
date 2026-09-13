<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;

/**
 * Configuración SMTP por instalación, guardada cifrada en disco y aplicada en
 * runtime sobre config('mail.*'). Permite que cada empresa use su propio
 * proveedor (cPanel, Gmail, Outlook…) sin tocar código ni .env.
 */
class MailConfig
{
    protected static function path(): string
    {
        return storage_path('app/private/mail.json');
    }

    public static function exists(): bool
    {
        return File::exists(static::path());
    }

    public static function load(): ?array
    {
        if (! static::exists()) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString(File::get(static::path())), true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function save(array $data): void
    {
        File::ensureDirectoryExists(dirname(static::path()));
        File::put(static::path(), Crypt::encryptString(json_encode($data)));
    }

    public static function forget(): void
    {
        if (static::exists()) {
            File::delete(static::path());
        }
    }

    /** Aplica la config SMTP guardada sobre config('mail.*'). */
    public static function apply(?array $cfg = null): void
    {
        $cfg ??= static::load();
        if (! $cfg || empty($cfg['host'])) {
            return; // sin SMTP configurado: se usa el mailer por defecto (log)
        }

        $enc = ($cfg['encryption'] ?? null) ?: null; // 'ssl' | 'tls' | null

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $cfg['host']);
        Config::set('mail.mailers.smtp.port', (int) (($cfg['port'] ?? null) ?: 587));
        Config::set('mail.mailers.smtp.encryption', $enc);
        Config::set('mail.mailers.smtp.username', ($cfg['username'] ?? null) ?: null);
        Config::set('mail.mailers.smtp.password', ($cfg['password'] ?? null) ?: null);

        if (! empty($cfg['from_address'])) {
            Config::set('mail.from.address', $cfg['from_address']);
            Config::set('mail.from.name', ($cfg['from_name'] ?? null) ?: (string) config('app.name'));
        }
    }
}
