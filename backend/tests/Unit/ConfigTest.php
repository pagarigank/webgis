<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Save and clear environment variables before each test
        $vars = ['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET', 'APP_DEBUG'];
        foreach ($vars as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $var => $value) {
            if ($value !== false) {
                putenv("{$var}={$value}");
            }
        }
        parent::tearDown();
    }

    public function testLoadsConfigWhenAllRequiredVariablesArePresent(): void
    {
        putenv('APP_ENV=testing');
        putenv('DB_HOST=localhost');
        putenv('DB_PORT=5432');
        putenv('DB_NAME=testdb');
        putenv('DB_USER=testuser');
        putenv('DB_PASS=testpass');
        putenv('JWT_SECRET=supersecret');

        $config = Config::load();

        $this->assertSame('testing', $config->get('APP_ENV'));
        $this->assertSame('localhost', $config->get('DB_HOST'));
        $this->assertFalse($config->get('APP_DEBUG'));
    }

    public function testFailsLoudlyWhenRequiredVariableIsMissing(): void
    {
        putenv('APP_ENV=testing');
        putenv('DB_HOST=localhost');
        // Missing DB_PORT and others

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required configuration secret: DB_PORT');

        Config::load();
    }
}
