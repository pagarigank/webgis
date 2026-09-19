<?php
declare(strict_types=1);

namespace App\Core\Config;

class Config
{
    private array $settings;

    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }

    public static function load(string $envPath = ''): self
    {
        $settings = [];

        // Parse .env if path is provided and file exists
        if ($envPath !== '' && file_exists($envPath)) {
            $parsed = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
            if ($parsed !== false) {
                foreach ($parsed as $key => $value) {
                    // Do not overwrite existing environment variables
                    if (!isset($_SERVER[$key]) && !isset($_ENV[$key])) {
                        putenv(sprintf('%s=%s', $key, $value));
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                    }
                }
            }
        }

        // Required variables that must be present
        $required = [
            'APP_ENV',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'JWT_SECRET'
        ];

        foreach ($required as $req) {
            $val = getenv($req);
            if ($val === false || $val === '') {
                throw new \RuntimeException("Missing required configuration secret: {$req}");
            }
            $settings[$req] = $val;
        }
        
        // Optional variables
        $settings['APP_DEBUG'] = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);

        return new self($settings);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
