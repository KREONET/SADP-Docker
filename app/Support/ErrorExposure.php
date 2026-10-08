<?php

namespace App\Support;

use App\Exceptions\RateLimitException;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Error;
use ErrorException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Process\Exceptions\ProcessFailedException as IlluminateProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException as IlluminateProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Exception\ProcessFailedException as SymfonyProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Throwable;

/**
 * Decides whether an exception message may be shown to end users.
 *
 * The application deliberately throws plain exceptions with human readable
 * messages for user feedback, so classification is based on the exception
 * type and origin plus a set of message patterns that betray internals
 * (SQL errors, filesystem paths, stack traces). In production, internal
 * errors are replaced by a generic message with a short reference id, and the
 * full message is written to the log under that reference.
 */
class ErrorExposure
{
    /**
     * Environments where raw messages are shown to help development and tests.
     *
     * @var array<int, string>
     */
    public const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing', 'dev', 'development'];

    /**
     * Exception types whose messages are written for end users.
     *
     * @var array<int, class-string>
     */
    private const USER_FACING_TYPES = [
        ValidationException::class,
        AuthorizationException::class,
        RateLimitException::class,
        TooManyRequestsException::class,
        ThrottleRequestsException::class,
    ];

    /**
     * Exception types that always carry internal details.
     *
     * @var array<int, class-string>
     */
    private const INTERNAL_TYPES = [
        QueryException::class,
        PDOException::class,
        ErrorException::class,
        Error::class,
        SymfonyProcessFailedException::class,
        SymfonyProcessTimedOutException::class,
        IlluminateProcessFailedException::class,
        IlluminateProcessTimedOutException::class,
        GuzzleException::class,
        ConnectionException::class,
        RequestException::class,
        FilesystemException::class,
        FileNotFoundException::class,
    ];

    /**
     * Message patterns that reveal internals (SQL, paths, stack traces, class names).
     *
     * @var array<int, string>
     */
    private const INTERNAL_MESSAGE_PATTERNS = [
        '/SQLSTATE\[/i',
        '/\bPDO(?:Exception|Statement)?\b/',
        '#/var/www\b#',
        '#/data/coolify\b#',
        '#(?:^|[\s\'"(:=])/root(?:/|\b)#',
        '#\bstorage/#',
        '#/vendor/#',
        '/Stack trace:/i',
        '/(?:^|\s)#\d+\s+(?:\/|\{main\}|\[internal function\])/m',
        '/\.php(?::\d+|\s+on\s+line\s+\d+|\s+line\s+\d+|\(\d+\))/i',
        '/\b(?:[A-Z][A-Za-z0-9_]*\\\\){2,}[A-Z][A-Za-z0-9_]*/',
    ];

    public function __construct(private readonly ?bool $concealInternalErrors = null) {}

    /**
     * Resolve the configured instance (tests may bind a production-like one).
     */
    public static function make(): self
    {
        return app(self::class);
    }

    /**
     * Whether internal error details are hidden from users in this environment.
     */
    public function concealsInternalErrors(): bool
    {
        if ($this->concealInternalErrors !== null) {
            return $this->concealInternalErrors;
        }

        return ! app()->environment(self::DEVELOPMENT_ENVIRONMENTS);
    }

    /**
     * Whether the exception message was written for end users.
     */
    public function isUserFacing(Throwable $error): bool
    {
        $error = $this->unwrap($error);

        foreach (self::USER_FACING_TYPES as $type) {
            if ($error instanceof $type) {
                return true;
            }
        }

        foreach (self::INTERNAL_TYPES as $type) {
            if ($error instanceof $type) {
                return false;
            }
        }

        if ($this->looksInternal($error->getMessage())) {
            return false;
        }

        if ($error instanceof HttpExceptionInterface) {
            return true;
        }

        if (! $this->isApplicationException($error) && $this->isThrownFromVendor($error)) {
            return false;
        }

        if ($error instanceof RuntimeException && ! $this->isApplicationException($error) && $error::class !== RuntimeException::class) {
            return false;
        }

        return true;
    }

    /**
     * Whether the exception carries internal details that must not be shown in production.
     */
    public function isInternal(Throwable $error): bool
    {
        return ! $this->isUserFacing($error);
    }

    /**
     * Whether a message contains SQL errors, filesystem paths, stack traces or class names.
     */
    public function looksInternal(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        foreach (self::INTERNAL_MESSAGE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message that may be shown to the user for this exception.
     *
     * Outside production the raw message is returned unchanged. In production,
     * authorization failures get a generic "not allowed" text and internal
     * errors get a generic message with a reference id that is also logged
     * together with the full exception.
     */
    public function userMessage(?Throwable $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $message = $error->getMessage();

        if (! $this->concealsInternalErrors()) {
            return $message;
        }

        $unwrapped = $this->unwrap($error);

        if ($unwrapped instanceof AuthorizationException) {
            return __('You are not allowed to perform this action.');
        }

        if ($this->isUserFacing($error)) {
            return $message;
        }

        $reference = $this->newReference();

        Log::error('Concealed internal error shown to user [ref '.$reference.']: '.$unwrapped->getMessage(), [
            'reference' => $reference,
            'exception' => $unwrapped,
        ]);

        return $this->genericMessage($reference);
    }

    /**
     * Generic text shown instead of an internal error message.
     */
    public function genericMessage(string $reference): string
    {
        return __('An unexpected error occurred. Please contact your administrator with reference :reference.', [
            'reference' => $reference,
        ]);
    }

    /**
     * Short, human-friendly reference id that links the UI message to the log entry.
     */
    public function newReference(): string
    {
        return 'ERR-'.Str::upper(Str::random(8));
    }

    /**
     * HTTP exceptions created by the framework wrap the original error as the previous exception.
     */
    private function unwrap(Throwable $error): Throwable
    {
        if ($error instanceof HttpExceptionInterface && $error->getPrevious() !== null) {
            return $error->getPrevious();
        }

        return $error;
    }

    private function isApplicationException(Throwable $error): bool
    {
        return str_starts_with($error::class, 'App\\');
    }

    /**
     * Exceptions created inside vendor code carry library messages, not ours.
     * Laravel's throw_if()/throw_unless() helpers create app exceptions in vendor files, so they are excluded.
     */
    private function isThrownFromVendor(Throwable $error): bool
    {
        $file = str_replace('\\', '/', $error->getFile());

        if (! str_contains($file, '/vendor/')) {
            return false;
        }

        return ! str_ends_with($file, '/Illuminate/Support/helpers.php')
            && ! str_ends_with($file, '/Illuminate/Collections/helpers.php');
    }
}
