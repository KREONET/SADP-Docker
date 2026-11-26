<?php

namespace App\Services\ServerTransfer;

use App\Models\Server;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TransferBackToken
{
    public const ABILITY = 'server:return';

    /** @return list<string> */
    public static function abilities(Server $archive): array
    {
        return [self::ABILITY, "archive:{$archive->id}:{$archive->uuid}"];
    }

    /**
     * Limit the callback credential before it reaches any REST or MCP handler.
     * The old token format can still be in flight for an hour during an upgrade.
     */
    public static function allows(Request $request, PersonalAccessToken $token): bool
    {
        $abilities = $token->abilities ?? [];
        if (in_array(self::ABILITY, $abilities, true)) {
            $scope = $abilities[1] ?? '';
            if (! is_string($scope) || ! preg_match('/\Aarchive:(\d+):([a-zA-Z0-9_-]{1,64})\z/', $scope, $match)) {
                return false;
            }
            $archiveId = (int) $match[1];
            $uuid = $match[2];
        } elseif ($abilities === ['write', 'deploy'] && $token->expires_at && $token->created_at
            && $token->expires_at->lte($token->created_at->copy()->addSeconds(ServerTransferMigrator::TRANSFER_BACK_TOKEN_SECONDS))
            && preg_match('/\Atransfer back ([a-zA-Z0-9_-]{1,64})\z/', $token->name, $match)) {
            $archiveId = null;
            $uuid = $match[1];
        } else {
            return true;
        }

        if ($request->routeIs('api.server-transfer.status')) {
            if ($request->route('uuid') !== $uuid) {
                return false;
            }
            $request->attributes->set('transfer_back_status', true);

            return true;
        }

        if ($request->routeIs('api.server-transfer.check')) {
            $serverUuid = $request->query('server_uuid');
            $ip = $request->query('ip');
            $port = $request->query('port', 22);
        } elseif ($request->routeIs('api.server-transfer.import')
            && ! $request->input('bundle.encrypted')
            && $request->boolean('preserve_uuids', true) && $request->boolean('adopt_mode', true)) {
            $serverUuid = $request->input('bundle.server.uuid');
            $ip = $request->input('bundle.server.ip');
            $port = $request->input('bundle.server.port', 22);
        } else {
            return false;
        }

        if ($serverUuid !== $uuid) {
            return false;
        }
        $archive = Server::whereTeamId($token->team_id)->whereUuid($uuid)->first();
        if (! $archive?->isTransferredAway() || ($archiveId !== null && $archive->id !== $archiveId)
            || $archive->ip !== $ip || filter_var($port, FILTER_VALIDATE_INT) !== (int) ($archive->port ?: 22)) {
            return false;
        }
        $request->attributes->set('transfer_back_archive_id', $archive->id);

        return true;
    }
}
