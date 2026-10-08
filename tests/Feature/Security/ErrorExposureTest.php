<?php

use App\Exceptions\RateLimitException;
use App\Support\ErrorExposure;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Process\Exceptions\ProcessFailedException as IlluminateProcessFailedException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToReadFile;
use Symfony\Component\Console\Exception\RuntimeException as SymfonyConsoleRuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Exception\ProcessFailedException as SymfonyProcessFailedException;
use Symfony\Component\Process\Process;

function productionErrorExposure(): ErrorExposure
{
    return new ErrorExposure(concealInternalErrors: true);
}

function exceptionThrownFromVendor(Throwable $error): Throwable
{
    (new ReflectionProperty(Exception::class, 'file'))->setValue($error, base_path('vendor/acme/library/src/Client.php'));

    return $error;
}

describe('environment detection', function () {
    it('shows raw messages in local and testing environments', function () {
        expect((new ErrorExposure)->concealsInternalErrors())->toBeFalse();

        app()->detectEnvironment(fn () => 'local');
        expect((new ErrorExposure)->concealsInternalErrors())->toBeFalse();
    });

    it('conceals internal errors in production and staging', function (string $environment) {
        app()->detectEnvironment(fn () => $environment);

        expect((new ErrorExposure)->concealsInternalErrors())->toBeTrue();
    })->with(['production', 'staging']);

    it('allows the behaviour to be forced explicitly', function () {
        expect(productionErrorExposure()->concealsInternalErrors())->toBeTrue()
            ->and((new ErrorExposure(concealInternalErrors: false))->concealsInternalErrors())->toBeFalse();
    });
});

describe('classification', function () {
    it('treats internal exception types as internal', function (Throwable $error) {
        expect(productionErrorExposure()->isInternal($error))->toBeTrue();
    })->with([
        'query exception' => fn () => new QueryException('pgsql', 'select * from users where id = ?', [1], new PDOException('connection refused')),
        'pdo exception' => fn () => new PDOException('could not find driver'),
        'error exception' => fn () => new ErrorException('Undefined array key "name"'),
        'type error' => fn () => new TypeError('Argument #1 must be of type string, null given'),
        'error' => fn () => new Error('Call to a member function save() on null'),
        'symfony process failed' => function () {
            $process = new Process(['false']);
            $process->run();

            return new SymfonyProcessFailedException($process);
        },
        'illuminate process failed' => fn () => new IlluminateProcessFailedException(new FakeProcessResult(command: 'ls', exitCode: 1, errorOutput: 'denied')),
        'guzzle exception' => fn () => new ConnectException('cURL error 7: Failed to connect', new PsrRequest('GET', 'https://internal.example')),
        'http client connection exception' => fn () => new ConnectionException('Connection refused'),
        'filesystem exception' => fn () => UnableToReadFile::fromLocation('backups/dump.sql'),
        'vendor runtime exception subclass' => fn () => new SymfonyConsoleRuntimeException('Not enough arguments'),
        'runtime exception thrown in vendor code' => fn () => exceptionThrownFromVendor(new RuntimeException('Unable to open stream')),
        'plain exception thrown in vendor code' => fn () => exceptionThrownFromVendor(new InvalidArgumentException('Malformed UTF-8 characters')),
    ]);

    it('treats messages that reveal internals as internal', function (string $message) {
        expect(productionErrorExposure()->looksInternal($message))->toBeTrue()
            ->and(productionErrorExposure()->isInternal(new Exception($message)))->toBeTrue();
    })->with([
        'sqlstate' => 'SQLSTATE[23505]: Unique violation',
        'web root path' => 'Failed to open /var/www/html/storage/app/key',
        'coolify data path' => 'Directory /data/coolify/backups/abc not found',
        'root home path' => 'Permission denied: /root/.ssh/id_ed25519',
        'storage path' => 'Unable to write storage/logs/laravel.log',
        'stack trace' => "Something failed\nStack trace:\n#0 /app/Foo.php(10): bar()",
        'stack frame' => 'Failed #0 {main}',
        'php file and line' => 'Error in Server.php:123',
        'php file on line' => 'Undefined variable in Helpers.php on line 42',
        'namespaced class' => 'Unresolvable dependency App\\Services\\Foo\\Bar',
    ]);

    it('treats deliberate application messages as user facing', function (Throwable $error) {
        expect(productionErrorExposure()->isUserFacing($error))->toBeTrue();
    })->with([
        'plain exception' => fn () => new Exception('Server is not reachable. Please validate the connection.'),
        'runtime exception from app code' => fn () => new RuntimeException('Container not found.'),
        'app exception' => fn () => new RateLimitException('Rate limit exceeded.'),
        'validation exception' => fn () => ValidationException::withMessages(['name' => 'The name field is required.']),
        'livewire rate limit' => fn () => new TooManyRequestsException('submit', 'Help', '127.0.0.1', 30),
        'authorization exception' => fn () => new AuthorizationException('This action is unauthorized.'),
        'deliberate http exception' => fn () => new HttpException(400, 'Invalid webhook payload.'),
    ]);

    it('classifies the wrapped error of framework http exceptions', function () {
        $wrapped = new HttpException(500, 'SQLSTATE hidden', new QueryException('pgsql', 'select 1', [], new PDOException('boom')));

        expect(productionErrorExposure()->isInternal($wrapped))->toBeTrue();
    });

    it('does not treat empty messages as internal', function () {
        expect(productionErrorExposure()->looksInternal(''))->toBeFalse()
            ->and(productionErrorExposure()->looksInternal(null))->toBeFalse()
            ->and(productionErrorExposure()->looksInternal('Please add a storage destination first.'))->toBeFalse();
    });
});

describe('user message', function () {
    it('returns raw messages when internals are not concealed', function () {
        $exposure = new ErrorExposure(concealInternalErrors: false);

        expect($exposure->userMessage(new PDOException('SQLSTATE[08006] connection failed')))
            ->toBe('SQLSTATE[08006] connection failed');
    });

    it('returns null without an error', function () {
        expect(productionErrorExposure()->userMessage(null))->toBeNull();
    });

    it('keeps deliberate messages in production', function () {
        expect(productionErrorExposure()->userMessage(new Exception('Server is not reachable.')))
            ->toBe('Server is not reachable.');
    });

    it('keeps validation messages in production', function () {
        $error = ValidationException::withMessages(['name' => 'The name field is required.']);

        expect(productionErrorExposure()->userMessage($error))->toBe('The name field is required.');
    });

    it('uses a generic not allowed text for authorization errors in production', function () {
        expect(productionErrorExposure()->userMessage(new AuthorizationException('Policy ServerPolicy::update denied')))
            ->toBe('You are not allowed to perform this action.');
    });

    it('replaces internal errors with a generic message and logs the reference', function () {
        Log::spy();

        $message = productionErrorExposure()->userMessage(new PDOException('SQLSTATE[08006] password authentication failed for user "coolify"'));

        expect($message)
            ->toStartWith('An unexpected error occurred.')
            ->not->toContain('SQLSTATE')
            ->not->toContain('coolify"')
            ->toMatch('/reference ERR-[A-Z0-9]{8}\./');

        preg_match('/ERR-[A-Z0-9]{8}/', $message, $matches);
        $reference = $matches[0];

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $logMessage, array $context) use ($reference) {
            return str_contains($logMessage, $reference)
                && str_contains($logMessage, 'SQLSTATE[08006]')
                && $context['reference'] === $reference
                && $context['exception'] instanceof PDOException;
        });
    });

    it('generates distinct references', function () {
        $exposure = productionErrorExposure();

        expect($exposure->newReference())->not->toBe($exposure->newReference())
            ->and($exposure->newReference())->toMatch('/^ERR-[A-Z0-9]{8}$/');
    });
});
