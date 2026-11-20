<?php

namespace App\Http\Controllers\Scim;

use App\Actions\User\DeactivateUserAccount;
use App\Actions\User\DeleteUserAccount;
use App\Http\Controllers\Controller;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * SCIM 2.0 Users resource (RFC 7643/7644) so the identity provider can provision and deprovision accounts.
 *
 * DELETE permanently deletes the account through {@see DeleteUserAccount}. PUT/PATCH setting `active`
 * to false only deactivates it through {@see DeactivateUserAccount} (signed out, cannot sign in, data
 * kept), because identity providers also send that for suspensions and app unassignments that may be
 * undone; `active: true` reactivates it. The root user (id 0) is never listed and cannot be changed.
 */
class UsersController extends Controller
{
    private const MAX_COUNT = 200;

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->where('id', '>', 0)->orderBy('id');

        $filter = trim((string) $request->query('filter', ''));
        if ($filter !== '') {
            if (! preg_match('/^(userName|emails\.value|emails|externalId)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"$/i', $filter, $matches)) {
                return ScimResponse::error(400, 'Only "userName eq", "emails.value eq", and "externalId eq" filters are supported.', 'invalidFilter');
            }

            $attribute = strtolower($matches[1]);
            $value = stripslashes($matches[2]);

            if ($attribute === 'externalid') {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('email', Str::lower($value));
            }
        }

        $startIndex = max(1, (int) $request->query('startIndex', 1));
        $count = min(self::MAX_COUNT, max(0, (int) $request->query('count', 100)));
        $total = (clone $query)->count();
        $users = $count === 0 ? collect() : $query->skip($startIndex - 1)->take($count)->get();

        return ScimResponse::json([
            'schemas' => [ScimResponse::LIST_SCHEMA],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $users->count(),
            'Resources' => $users->map(fn (User $user) => $this->representation($user))->values()->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $user = $this->findUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return ScimResponse::json($this->representation($user));
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $this->userAttributes($request->all());
        if ($attributes instanceof JsonResponse) {
            return $attributes;
        }

        if (User::query()->where('email', $attributes['email'])->exists()) {
            return ScimResponse::error(409, 'A user with this userName already exists.', 'uniqueness');
        }

        $user = $this->createUser($attributes['name'], $attributes['email']);

        auditLog('scim.user.created', ['resource' => 'user', 'scim_user_id' => $user->id, 'scim_user_email' => $user->email]);

        if ($request->has('active') && $this->parseBoolean($request->input('active')) === false) {
            DeactivateUserAccount::execute($user, 'scim');
            $user->refresh();
        }

        return ScimResponse::json($this->representation($user), 201, ['Location' => $this->location($user)]);
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $attributes = $this->userAttributes($request->all());
        if ($attributes instanceof JsonResponse) {
            return $attributes;
        }

        $active = $request->has('active') ? $this->parseBoolean($request->input('active')) : null;

        return $this->applyChanges($user, $attributes, $active);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $operations = $request->input('Operations');
        if (! is_array($operations) || $operations === []) {
            return ScimResponse::error(400, 'The PatchOp request must contain Operations.', 'invalidSyntax');
        }

        $changes = [];
        foreach ($operations as $operation) {
            $op = Str::lower((string) data_get($operation, 'op'));
            if (! in_array($op, ['add', 'replace', 'remove'], true)) {
                return ScimResponse::error(400, 'Unsupported patch operation.', 'invalidSyntax');
            }
            if ($op === 'remove') {
                continue;
            }

            $path = data_get($operation, 'path');
            $value = data_get($operation, 'value');
            $values = filled($path) ? $this->expandPath((string) $path, $value) : (is_array($value) ? $value : []);
            $changes = array_replace_recursive($changes, $values);
        }

        $attributes = $this->userAttributes($changes, $user);
        if ($attributes instanceof JsonResponse) {
            return $attributes;
        }

        $active = array_key_exists('active', $changes) ? $this->parseBoolean($changes['active']) : null;

        return $this->applyChanges($user, $attributes, $active);
    }

    public function destroy(string $id): Response|JsonResponse
    {
        $user = $this->findUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            DeleteUserAccount::execute($user, 'scim');
        } catch (Throwable $e) {
            return ScimResponse::error(500, 'The user could not be deleted: '.$e->getMessage());
        }

        return response()->noContent();
    }

    /**
     * @param  array{name: string, email: string}  $attributes
     */
    private function applyChanges(User $user, array $attributes, ?bool $active = null): JsonResponse
    {
        if ($attributes['email'] !== $user->email
            && User::query()->where('email', $attributes['email'])->whereKeyNot($user->id)->exists()) {
            return ScimResponse::error(409, 'A user with this userName already exists.', 'uniqueness');
        }

        $user->forceFill(['name' => $attributes['name'], 'email' => $attributes['email']]);
        $changedFields = array_keys($user->getDirty());
        $user->save();

        if ($changedFields !== []) {
            auditLog('scim.user.updated', ['resource' => 'user', 'scim_user_id' => $user->id, 'changed_fields' => $changedFields]);
        }

        if ($active === false) {
            DeactivateUserAccount::execute($user, 'scim');
        } elseif ($active === true) {
            DeactivateUserAccount::reactivate($user, 'scim');
        }

        return ScimResponse::json($this->representation($user->fresh()));
    }

    private function findUser(string $id): User|JsonResponse
    {
        if (! ctype_digit($id)) {
            return ScimResponse::error(404, 'User not found.');
        }

        if ((int) $id === 0) {
            return ScimResponse::error(403, 'The root user cannot be managed through SCIM.');
        }

        return User::query()->find((int) $id) ?? ScimResponse::error(404, 'User not found.');
    }

    /**
     * Resolve the account email and display name from a SCIM User payload, falling back to the existing user.
     *
     * @param  array<string, mixed>  $payload
     * @return array{name: string, email: string}|JsonResponse
     */
    private function userAttributes(array $payload, ?User $existing = null): array|JsonResponse
    {
        $email = data_get($payload, 'userName');
        $emails = data_get($payload, 'emails');
        if (blank($email) && is_array($emails) && $emails !== []) {
            $primary = collect($emails)->first(fn ($entry) => (bool) data_get($entry, 'primary')) ?? collect($emails)->first();
            $email = data_get($primary, 'value');
        }
        $email = Str::lower(trim((string) ($email ?? $existing?->email)));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            return ScimResponse::error(400, 'userName must be a valid email address.', 'invalidValue');
        }

        $name = data_get($payload, 'displayName')
            ?: data_get($payload, 'name.formatted')
            ?: trim(data_get($payload, 'name.givenName', '').' '.data_get($payload, 'name.familyName', ''))
            ?: ($existing?->name ?? Str::before($email, '@'));

        return ['name' => Str::limit(trim((string) $name), 255, ''), 'email' => $email];
    }

    /**
     * Turn a PatchOp path such as `name.givenName` or `emails[type eq "work"].value` into a nested payload.
     *
     * @return array<string, mixed>
     */
    private function expandPath(string $path, mixed $value): array
    {
        $path = (string) preg_replace('/^'.preg_quote(ScimResponse::USER_SCHEMA, '/').':/i', '', $path);

        if (preg_match('/^emails(\[.*\])?(\.value)?$/i', $path)) {
            $email = is_array($value) ? data_get($value, '0.value', data_get($value, 'value')) : $value;

            return ['emails' => [['value' => $email, 'primary' => true]]];
        }

        $segments = explode('.', $path);
        $canonical = ['username' => 'userName', 'displayname' => 'displayName', 'givenname' => 'givenName', 'familyname' => 'familyName'];
        $segments = array_map(fn (string $segment) => $canonical[Str::lower($segment)] ?? $segment, $segments);

        $result = [];
        data_set($result, implode('.', $segments), $value);

        return $result;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Same account shape as an OIDC login would create, so the first OIDC sign-in links to it by email.
     */
    private function createUser(string $name, string $email): User
    {
        $attributes = [
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
        ];

        $joinRootTeamOnly = (bool) OauthSetting::query()->where('provider', 'oidc')->value('auto_join_root_team');
        $rootTeam = $joinRootTeamOnly ? Team::find(0) : null;

        return DB::transaction(function () use ($attributes, $rootTeam) {
            if ($rootTeam !== null) {
                $user = User::withoutEvents(fn () => User::create($attributes));
                $user->teams()->attach($rootTeam, ['role' => 'member']);
            } else {
                $user = User::create($attributes);
            }

            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function representation(User $user): array
    {
        return [
            'schemas' => [ScimResponse::USER_SCHEMA],
            'id' => (string) $user->id,
            'userName' => $user->email,
            'displayName' => $user->name,
            'name' => ['formatted' => $user->name],
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => ! $user->isDeactivated(),
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
                'location' => $this->location($user),
            ],
        ];
    }

    private function location(User $user): string
    {
        return url('/api/scim/v2/Users/'.$user->id);
    }
}
