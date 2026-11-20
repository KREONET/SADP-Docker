<?php

namespace App\Http\Controllers\Scim;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * SCIM 2.0 discovery endpoints (RFC 7644 section 4) that identity providers read before provisioning.
 */
class DiscoveryController extends Controller
{
    public function serviceProviderConfig(): JsonResponse
    {
        return ScimResponse::json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => url('/settings/oauth/oidc'),
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'OAuth Bearer Token',
                'description' => 'Bearer token issued on the OIDC provider settings page.',
                'primary' => true,
            ]],
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => url('/api/scim/v2/ServiceProviderConfig')],
        ]);
    }

    public function resourceTypes(): JsonResponse
    {
        return ScimResponse::json([
            'schemas' => [ScimResponse::LIST_SCHEMA],
            'totalResults' => 1,
            'Resources' => [$this->userResourceType()],
        ]);
    }

    public function schemas(): JsonResponse
    {
        return ScimResponse::json([
            'schemas' => [ScimResponse::LIST_SCHEMA],
            'totalResults' => 1,
            'Resources' => [$this->userSchema()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userResourceType(): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
            'id' => 'User',
            'name' => 'User',
            'endpoint' => '/Users',
            'schema' => ScimResponse::USER_SCHEMA,
            'meta' => ['resourceType' => 'ResourceType', 'location' => url('/api/scim/v2/ResourceTypes/User')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userSchema(): array
    {
        $attribute = fn (string $name, string $type, bool $required = false, array $extra = []): array => array_merge([
            'name' => $name,
            'type' => $type,
            'multiValued' => false,
            'required' => $required,
            'caseExact' => false,
            'mutability' => 'readWrite',
            'returned' => 'default',
            'uniqueness' => 'none',
        ], $extra);

        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            'id' => ScimResponse::USER_SCHEMA,
            'name' => 'User',
            'description' => 'User account',
            'attributes' => [
                $attribute('userName', 'string', true, ['uniqueness' => 'server', 'description' => 'Email address used to sign in.']),
                $attribute('displayName', 'string'),
                $attribute('name', 'complex', false, ['subAttributes' => [
                    $attribute('formatted', 'string'),
                    $attribute('givenName', 'string'),
                    $attribute('familyName', 'string'),
                ]]),
                $attribute('emails', 'complex', false, ['multiValued' => true, 'subAttributes' => [
                    $attribute('value', 'string'),
                    $attribute('type', 'string'),
                    $attribute('primary', 'boolean'),
                ]]),
                $attribute('active', 'boolean', false, ['description' => 'Setting active to false permanently deletes the account.']),
            ],
            'meta' => ['resourceType' => 'Schema', 'location' => url('/api/scim/v2/Schemas/'.ScimResponse::USER_SCHEMA)],
        ];
    }
}
