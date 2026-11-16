<?php

declare(strict_types=1);

namespace Ralffreit\MfaEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ralffreit\MfaEmail\Mfa\MailProvider;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderPropertyManager;
use TYPO3\CMS\Core\Http\ServerRequest;

/** Tests use the real TYPO3 interfaces, replacing only persistence and the clock. */
final class MailProviderTest extends TestCase
{
    private function properties(array $overrides = []): MemoryPropertyManager
    {
        return new MemoryPropertyManager(array_replace([
            'active' => true,
            'email' => 'user@example.com',
            'authCode' => '012345',
            'authCodeCreated' => 1000,
            'attempts' => 0,
        ], $overrides));
    }

    public function testSuccessfulCodeIsSingleUse(): void
    {
        $provider = new TestableMailProvider();
        $properties = $this->properties();
        $request = (new ServerRequest())->withParsedBody(['authCode' => '012345']);
        self::assertTrue($provider->verify($request, $properties));
        self::assertSame('', $properties->getProperty('authCode'));
        self::assertSame(0, $properties->getProperty('authCodeCreated'));
        self::assertSame(1001, $properties->getProperty('lastUsed'));
        self::assertFalse($provider->verify($request, $properties));
    }

    public function testEmptyCodesCannotBypassMfa(): void
    {
        $provider = new TestableMailProvider();
        foreach (['', '012345'] as $stored) {
            foreach (['', '999999'] as $input) {
                self::assertFalse($provider->verify(
                    (new ServerRequest())->withParsedBody(['authCode' => $input]),
                    $this->properties(['authCode' => $stored])
                ));
            }
        }
    }

    public function testExpiredAndLegacyCodesAreRejectedAndCleared(): void
    {
        foreach ([0, 100] as $created) {
            $properties = $this->properties(['authCodeCreated' => $created]);
            self::assertFalse((new TestableMailProvider())->verify(
                (new ServerRequest())->withQueryParams(['authCode' => '012345']), $properties
            ));
            self::assertSame('', $properties->getProperty('authCode'));
            self::assertSame(0, $properties->getProperty('authCodeCreated'));
        }
    }

    public function testFailedAttemptsLockAndInvalidateCode(): void
    {
        $provider = new TestableMailProvider();
        $properties = $this->properties(['attempts' => 5]);
        self::assertFalse($provider->verify(
            (new ServerRequest())->withParsedBody(['authCode' => '999999']), $properties
        ));
        self::assertSame(6, $properties->getProperty('attempts'));
        self::assertTrue($provider->isLocked($properties));
        self::assertSame('', $properties->getProperty('authCode'));
        self::assertSame(0, $properties->getProperty('authCodeCreated'));
        self::assertTrue($provider->unlock(new ServerRequest(), $properties));
        self::assertFalse($provider->verify(
            (new ServerRequest())->withParsedBody(['authCode' => '012345']), $properties
        ));
    }

    public function testInactiveOrLockedProviderCannotVerify(): void
    {
        foreach ([['active' => false], ['attempts' => 6]] as $overrides) {
            self::assertFalse((new TestableMailProvider())->verify(
                (new ServerRequest())->withParsedBody(['authCode' => '012345']),
                $this->properties($overrides)
            ));
        }
    }

    public function testUnsafeConfigurationFallsBackToSecureDefaults(): void
    {
        foreach ([[], ['maxAttempts' => 'invalid', 'codeValidityPeriod' => 'invalid'], ['maxAttempts' => 0]] as $config) {
            $provider = new TestableMailProvider($config);
            self::assertSame(6, $provider->maxAttempts());
            self::assertSame(900, $provider->validityPeriod());
        }
        self::assertSame(PHP_INT_MAX, (new TestableMailProvider(['maxAttempts' => -1]))->maxAttempts());
        self::assertSame(0, (new TestableMailProvider(['codeValidityPeriod' => -1]))->validityPeriod());
    }

    public function testActivationClearsPreviousCodeAndAttempts(): void
    {
        $properties = $this->properties(['attempts' => 5]);
        self::assertTrue((new TestableMailProvider())->activate(
            (new ServerRequest())->withParsedBody(['email' => ' new@example.com ']), $properties
        ));
        self::assertSame('new@example.com', $properties->getProperty('email'));
        self::assertSame('', $properties->getProperty('authCode'));
        self::assertSame(0, $properties->getProperty('authCodeCreated'));
        self::assertSame(0, $properties->getProperty('attempts'));
    }

    public function testGeneratedCodesAreSixDigitStrings(): void
    {
        $provider = new TestableMailProvider();
        for ($i = 0; $i < 100; $i++) {
            self::assertMatchesRegularExpression('/^[0-9]{6}$/D', $provider->code());
        }
    }

    public function testMalformedCodeInputIsRejectedWithoutWarnings(): void
    {
        self::assertFalse((new TestableMailProvider())->verify(
            (new ServerRequest())->withParsedBody(['authCode' => ['012345']]), $this->properties()
        ));
    }

    public function testMalformedEmailInputIsRejectedWithoutWarnings(): void
    {
        $provider = new TestableMailProvider();
        self::assertFalse($provider->update(
            (new ServerRequest())->withParsedBody(['email' => ['user@example.com']]), $this->properties()
        ));
    }

    public function testInactiveOrLockedProviderDoesNotGenerateOrSendCodes(): void
    {
        foreach ([['active' => false], ['attempts' => 6]] as $overrides) {
            $properties = $this->properties(array_replace(['authCode' => ''], $overrides));
            (new TestableMailProvider())->send($properties);
            self::assertSame('', $properties->getProperty('authCode'));
        }
    }

    public function testUnpersistedCodeIsNotSent(): void
    {
        $properties = $this->properties(['authCode' => '']);
        $properties->writesSucceed = false;
        (new TestableMailProvider())->send($properties);
        self::assertSame('', $properties->getProperty('authCode'));
    }
}

final class TestableMailProvider extends MailProvider
{
    public function __construct(array $config = [])
    {
        $this->extensionConfiguration = $config;
    }

    protected function getTimestamp(): int
    {
        return 1001;
    }

    protected function showLocalizedFlashMessage(string $messageKey): void {}

    public function maxAttempts(): int
    {
        return $this->getMaxAttempts();
    }

    public function validityPeriod(): int
    {
        return $this->getCodeValidityPeriod();
    }

    public function code(): string
    {
        return $this->generateAuthCode();
    }

    public function send(MfaProviderPropertyManager $properties): void
    {
        $this->sendAuthCodeEmail($properties);
    }
}

final class MemoryPropertyManager extends MfaProviderPropertyManager
{
    public bool $writesSucceed = true;

    public function __construct(array $properties)
    {
        $this->providerProperties = $properties;
    }

    public function hasProviderEntry(): bool
    {
        return true;
    }

    public function updateProperties(array $properties): bool
    {
        if (!$this->writesSucceed) {
            return false;
        }
        $this->providerProperties = array_replace($this->providerProperties, $properties);
        return true;
    }
}
