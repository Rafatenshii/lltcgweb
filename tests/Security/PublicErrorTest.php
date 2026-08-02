<?php

declare(strict_types=1);

namespace LLTCG\Tests\Security;

use Error;
use Exception;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PublicErrorTest extends TestCase
{
    private ?string $prevDebug = null;
    private ?string $prevProduction = null;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/config/errors.php';
        $this->prevDebug = getenv('TCG_DEBUG') !== false ? (string)getenv('TCG_DEBUG') : null;
        $this->prevProduction = getenv('TCG_PRODUCTION') !== false ? (string)getenv('TCG_PRODUCTION') : null;
    }

    protected function tearDown(): void
    {
        if ($this->prevDebug === null) {
            putenv('TCG_DEBUG');
        } else {
            putenv('TCG_DEBUG=' . $this->prevDebug);
        }
        if ($this->prevProduction === null) {
            putenv('TCG_PRODUCTION');
        } else {
            putenv('TCG_PRODUCTION=' . $this->prevProduction);
        }
    }

    public function testProductionMasksInternalServerErrors(): void
    {
        putenv('TCG_DEBUG');
        putenv('TCG_PRODUCTION=1');
        $e = new RuntimeException('/var/www/html/secret/path failed');
        $this->assertSame('Server error', tcgPublicErrorMessage($e, 500));
    }

    public function testProductionKeepsValidationErrors(): void
    {
        putenv('TCG_DEBUG');
        putenv('TCG_PRODUCTION=1');
        $e = new InvalidArgumentException('card_no required');
        $this->assertSame('card_no required', tcgPublicErrorMessage($e, 400));
    }

    public function testDebugShowsFullMessage(): void
    {
        putenv('TCG_DEBUG=1');
        $e = new RuntimeException('detailed failure');
        $this->assertSame('detailed failure', tcgPublicErrorMessage($e, 500));
    }

    public function testHttpStatusMapsExceptionCodeZeroTo400(): void
    {
        $this->assertSame(400, tcgHttpStatusForThrowable(new Exception('Choose a starter deck first')));
    }

    public function testHttpStatusKeepsExplicitCodes(): void
    {
        $this->assertSame(401, tcgHttpStatusForThrowable(new Exception('Authentication required', 401)));
        $this->assertSame(429, tcgHttpStatusForThrowable(new Exception('Rate limit exceeded. Try again shortly.', 429)));
    }

    public function testHttpStatusMapsLockTimeoutTo503(): void
    {
        $this->assertSame(503, tcgHttpStatusForThrowable(new Exception('Lock timeout')));
        $this->assertSame(503, tcgHttpStatusForThrowable(new Exception('Cannot acquire lock')));
    }

    public function testHttpStatusMapsErrorAndPdoTo500(): void
    {
        $this->assertSame(500, tcgHttpStatusForThrowable(new Error('boom')));
        $this->assertSame(500, tcgHttpStatusForThrowable(new PDOException('SQLSTATE[HY000]: General error: 1 no such table')));
    }

    public function testPublicPayloadMarksLockRetryable(): void
    {
        putenv('TCG_DEBUG');
        putenv('TCG_PRODUCTION=1');
        $e = new Exception('Lock timeout');
        $payload = tcgPublicErrorPayload($e, 503);
        $this->assertSame('Server busy', $payload['error']);
        $this->assertTrue($payload['retryable']);
        $this->assertSame('lock_timeout', $payload['code']);
    }
}
