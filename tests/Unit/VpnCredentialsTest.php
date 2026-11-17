<?php

namespace Tests\Unit;

use App\Services\VpnService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VpnCredentialsTest extends TestCase
{
    #[DataProvider('validCredentials')]
    public function test_accepts_valid_credentials(string $username, string $password): void
    {
        VpnService::assertValidCredentials($username, $password);
        $this->addToAssertionCount(1);
    }

    public static function validCredentials(): array
    {
        return [
            'telegram username' => ['Some_User42', '123456789'],
            'generated login' => ['c_15_482', '987654321'],
            'dots and dashes' => ['john.doe-1', 'pa55-word'],
        ];
    }

    #[DataProvider('invalidCredentials')]
    public function test_rejects_credentials_that_could_break_secrets_file(string $username, string $password): void
    {
        $this->expectException(InvalidArgumentException::class);
        VpnService::assertValidCredentials($username, $password);
    }

    public static function invalidCredentials(): array
    {
        return [
            'empty username' => ['', '123456'],
            'newline injection' => ["evil\n%any : PSK \"x\"", '123456'],
            'quote in password' => ['user', 'abc"def'],
            'space in username' => ['two words', '123456'],
            'colon in username' => ['user:x', '123456'],
            'too short password' => ['user', '12'],
        ];
    }
}
