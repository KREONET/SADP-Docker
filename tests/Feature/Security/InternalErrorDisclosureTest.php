<?php

use App\Models\InstanceSettings;
use App\Support\ErrorExposure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function concealInternalErrorsLikeProduction(): void
{
    app()->instance(ErrorExposure::class, new ErrorExposure(concealInternalErrors: true));
}

function errorDisclosureRecordingComponent(): Component
{
    return new class extends Component
    {
        /** @var array<int, array{event: string, params: array<int, mixed>}> */
        public array $dispatched = [];

        public function dispatch($event, ...$params)
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];

            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };
}

function errorDisclosureQueryException(): QueryException
{
    return new QueryException('pgsql', 'select * from users where email = ?', ['admin@example.com'], new PDOException('SQLSTATE[08006] connection to server at "coolify-db" failed'));
}

describe('handleError', function () {
    it('keeps showing raw messages in the testing environment', function () {
        $component = errorDisclosureRecordingComponent();

        handleError(new Exception('Server is not reachable.'), $component);
        handleError(new PDOException('SQLSTATE[08006] failed'), $component);

        expect($component->dispatched[0]['params'][0])->toBe('Server is not reachable.')
            ->and($component->dispatched[1]['params'][0])->toBe('SQLSTATE[08006] failed');
    });

    it('dispatches a generic message with a reference for internal errors in production', function () {
        concealInternalErrorsLikeProduction();
        Log::spy();
        $component = errorDisclosureRecordingComponent();

        handleError(errorDisclosureQueryException(), $component);

        $message = $component->dispatched[0]['params'][0];
        expect($component->dispatched[0]['event'])->toBe('error')
            ->and($message)->toMatch('/^An unexpected error occurred\. .*ERR-[A-Z0-9]{8}/')
            ->and($message)->not->toContain('SQLSTATE')
            ->and($message)->not->toContain('admin@example.com')
            ->and($message)->not->toContain('coolify-db');

        preg_match('/ERR-[A-Z0-9]{8}/', $message, $matches);
        Log::shouldHaveReceived('error')->withArgs(fn (string $logMessage, array $context) => $context['reference'] === $matches[0]
            && str_contains($logMessage, 'SQLSTATE[08006]'));
    });

    it('keeps deliberate application messages in production', function () {
        concealInternalErrorsLikeProduction();
        $component = errorDisclosureRecordingComponent();

        handleError(new Exception('Server is not reachable. Please validate your configuration.'), $component);

        expect($component->dispatched[0]['params'][0])->toBe('Server is not reachable. Please validate your configuration.');
    });

    it('prefixes the custom message to the generic message in production', function () {
        concealInternalErrorsLikeProduction();
        $component = errorDisclosureRecordingComponent();

        handleError(new ErrorException('Undefined array key "port"'), $component, 'Could not save.');

        expect($component->dispatched[0]['params'][0])
            ->toStartWith('Could not save. An unexpected error occurred.')
            ->not->toContain('Undefined array key');
    });

    it('shows a generic not allowed text for authorization errors in production', function () {
        concealInternalErrorsLikeProduction();
        $component = errorDisclosureRecordingComponent();

        handleError(new AuthorizationException('This action is unauthorized.'), $component);

        expect($component->dispatched[0]['params'][0])->toBe('You are not allowed to perform this action.');
    });

    it('throws the concealed message when no component is given', function () {
        concealInternalErrorsLikeProduction();

        expect(fn () => handleError(new TypeError('Argument #1 ($server) must be of type App\\Models\\Server, null given')))
            ->toThrow(Exception::class, 'An unexpected error occurred.');
    });
});

describe('error pages', function () {
    it('hides internal messages on the 500 page in production', function () {
        concealInternalErrorsLikeProduction();
        $exception = new HttpException(500, errorDisclosureQueryException()->getMessage(), errorDisclosureQueryException());

        $html = view('errors.500', ['exception' => $exception])->render();

        expect($html)
            ->toContain('error-message')
            ->toContain('An unexpected error occurred.')
            ->toMatch('/ERR-[A-Z0-9]{8}/')
            ->not->toContain('SQLSTATE')
            ->not->toContain('admin@example.com');
    });

    it('keeps deliberate messages on the 500 page in production', function () {
        concealInternalErrorsLikeProduction();

        $html = view('errors.500', ['exception' => new HttpException(500, 'Payment provider is not configured.')])->render();

        expect($html)->toContain('Payment provider is not configured.');
    });

    it('hides internal messages on the 400 page in production', function () {
        concealInternalErrorsLikeProduction();
        $exception = new HttpException(400, 'Bad JSON', new ErrorException('json_decode(): /var/www/html/app/Foo.php line 12'));

        $html = view('errors.400', ['exception' => $exception])->render();

        expect($html)
            ->toContain('An unexpected error occurred.')
            ->not->toContain('/var/www/html');
    });

    it('keeps deliberate messages on the 400 page in production', function () {
        concealInternalErrorsLikeProduction();

        $html = view('errors.400', ['exception' => new HttpException(400, 'Invalid signature.')])->render();

        expect($html)->toContain('Invalid signature.');
    });
});
