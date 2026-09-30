<?php

namespace App\Http\Controllers\Webhook;

use App\Exceptions\InvalidWebhookPayloadException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Http\Controllers\Webhook\Concerns\ReadsWebhookPushPayload;
use App\Models\Application;
use App\Services\Platform\SourcePolicy;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Instance-wide Forgejo webhook.
 *
 * The platform administrator registers one system (or organization) webhook in Forgejo
 * pointing at /webhooks/source/forgejo/events, signed with the secret configured in
 * Platform Admin → Sources. Every push to a branch redeploys the applications that
 * build that repository and branch, so no per-application webhook setup is needed.
 */
class Forgejo extends Controller
{
    use DetectsSkipDeployCommits;
    use ReadsWebhookPushPayload;

    public function events(Request $request)
    {
        try {
            $secret = instanceSettings()->forgejo_webhook_secret;
            if (blank($secret)) {
                return response('Forgejo webhook is not configured.', 404);
            }

            $signature = (string) ($request->header('X-Forgejo-Signature')
                ?? $request->header('X-Gitea-Signature')
                ?? Str::after((string) $request->header('X-Hub-Signature-256'), 'sha256='));
            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            if ($signature === '' || ! hash_equals($expected, $signature)) {
                auditLogWebhookFailure('forgejo', 'invalid_signature', ['ip' => $request->ip()]);

                return response('Invalid signature.', 401);
            }

            $event = Str::lower((string) ($request->header('X-Forgejo-Event') ?? $request->header('X-Gitea-Event')));
            if ($event === 'ping') {
                return response('pong');
            }
            if ($event !== 'push') {
                return response("Nothing to do. Event '{$event}' is not supported.");
            }

            $payload = $request->collect();
            if (! Str::startsWith((string) $request->header('Content-Type'), 'application/json')) {
                $formPayload = data_get($payload, 'payload');
                $payload = is_string($formPayload) ? json_decode($formPayload, true) : null;
            }

            $branch = $this->webhookPushBranch(data_get($payload, 'ref'));
            if (! $branch) {
                return response('Nothing to do. No branch found in the request.');
            }
            if ($this->isWebhookBranchDeletionPush($payload)) {
                return response('Nothing to do. Branch deleted.');
            }

            $repositoryUrls = collect([
                data_get($payload, 'repository.html_url'),
                data_get($payload, 'repository.clone_url'),
                data_get($payload, 'repository.ssh_url'),
            ])
                ->filter(fn ($url) => is_string($url))
                ->map(fn (string $url) => SourcePolicy::normalizeGitRepository($url))
                ->filter()
                ->unique()
                ->values();
            if ($repositoryUrls->isEmpty()) {
                return response('Nothing to do. Invalid repository.');
            }

            $commit = $this->webhookCommitSha($payload, 'after');
            $changedFiles = $this->webhookPushChangedFiles($payload);
            $skipDeploy = self::shouldSkipDeploy($this->webhookPushCommitMessages($payload));

            $results = collect();
            foreach ($this->matchingApplications($branch, $repositoryUrls) as $application) {
                $results->push($this->deploy($application, $commit, $changedFiles, $skipDeploy));
            }

            if ($results->isEmpty()) {
                return response('Nothing to do. No application uses this repository and branch.');
            }

            return response($results->values());
        } catch (InvalidWebhookPayloadException $e) {
            return response($e->getMessage());
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    /**
     * @param  Collection<int, string>  $repositoryUrls  Normalized "host/owner/repo" values from the payload.
     * @return Collection<int, Application>
     */
    private function matchingApplications(string $branch, Collection $repositoryUrls): Collection
    {
        return Application::query()
            ->where('git_branch', $branch)
            ->with('source')
            ->get()
            ->filter(function (Application $application) use ($repositoryUrls): bool {
                $normalized = SourcePolicy::normalizeGitRepository((string) SourcePolicy::applicationRepositoryUrl($application));

                return $normalized !== null && $repositoryUrls->contains($normalized);
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function deploy(Application $application, ?string $commit, ?Collection $changedFiles, bool $skipDeploy): array
    {
        $result = [
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
        ];

        if (! $application->destination?->server?->isFunctional()) {
            return $result + ['status' => 'failed', 'message' => 'Server is not functional.'];
        }
        if (! $application->isDeployable()) {
            return $result + ['status' => 'failed', 'message' => 'Automatic deployments are disabled.'];
        }
        if (! $this->webhookPushMatchesWatchPaths($application, $changedFiles)) {
            return $result + ['status' => 'skipped', 'message' => 'Changed files do not match watch paths.'];
        }
        if ($skipDeploy) {
            return $result + ['status' => 'skipped', 'message' => 'All commits contain [skip cd] or [skip ci].'];
        }

        $deploymentUuid = new_public_id();
        $queued = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            force_rebuild: false,
            commit: $commit ?? 'HEAD',
            is_webhook: true,
        );

        if ($queued['status'] === 'queue_full' || $queued['status'] === 'skipped') {
            return $result + ['status' => 'skipped', 'message' => $queued['message']];
        }

        auditLog('webhook.deployment.queued', [
            'provider' => 'forgejo',
            'mode' => 'instance',
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
            'deployment_uuid' => $deploymentUuid,
            'commit' => $commit,
        ]);

        return $result + ['status' => 'success', 'message' => 'Deployment queued.', 'deployment_uuid' => $deploymentUuid];
    }
}
