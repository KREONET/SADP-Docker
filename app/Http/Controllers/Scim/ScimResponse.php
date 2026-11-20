<?php

namespace App\Http\Controllers\Scim;

use Illuminate\Http\JsonResponse;

/**
 * SCIM 2.0 (RFC 7644) responses use the application/scim+json media type.
 */
class ScimResponse
{
    public const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public const LIST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    public const PATCH_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    public const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, array_merge(['Content-Type' => 'application/scim+json'], $headers), JSON_UNESCAPED_SLASHES);
    }

    public static function error(int $status, string $detail, ?string $scimType = null): JsonResponse
    {
        return self::json(array_filter([
            'schemas' => [self::ERROR_SCHEMA],
            'status' => (string) $status,
            'scimType' => $scimType,
            'detail' => $detail,
        ], fn ($value) => $value !== null), $status);
    }
}
