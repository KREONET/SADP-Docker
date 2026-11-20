<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Names of the Docker volumes that Coolify creates for new resources: "{nickname}-{random}-{project}_{purpose}",
 * for example "inmd1-k3x9q2ab-my-shop_postgres-data".
 *
 * The nickname is the part of the creator's email before "@" and the project is the slug of the project name, so
 * an operator can tell on the server whose volume it is. The random part keeps names unique. The purpose (the
 * database data directory or the Compose volume key) tells the volumes of one resource apart and lets the Compose
 * parsers find the volume again on the next deployment. Slugs never contain "_", so the purpose is everything after
 * the last "_". A name is generated once and stored: renaming the project or the user later does not move the data.
 */
class PersistentVolumeName
{
    private const RANDOM_LENGTH = 8;

    private const NICKNAME_LENGTH = 20;

    private const PROJECT_LENGTH = 30;

    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*-[a-z0-9]{8}-[a-z0-9]+(?:-[a-z0-9]+)*_[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public static function generate(?Project $project, string $purpose, ?User $user = null): string
    {
        $user ??= self::creator($project);

        return implode('-', [
            self::slug($user ? Str::before($user->email, '@') : '', self::NICKNAME_LENGTH, 'user'),
            strtolower(Str::random(self::RANDOM_LENGTH)),
            self::slug((string) $project?->name, self::PROJECT_LENGTH, 'project'),
        ]).'_'.self::purposeSlug($purpose);
    }

    /**
     * A new name with the same purpose, for a copy of the volume (cloning a resource).
     */
    public static function regenerate(string $name, ?Project $project, ?User $user = null): string
    {
        return self::generate($project, Str::afterLast($name, '_'), $user);
    }

    public static function isGenerated(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    /**
     * The stored name of a Compose volume, so a redeployment keeps using the same volume: a generated name with this
     * purpose, or the name parser version 3+ used before ("{uuid}_{purpose}"). Preview suffixes ("-pr-{id}") are
     * ignored; the caller adds its own. Null when the volume has no name yet.
     *
     * @param  iterable<string>  $existingNames  names of the volumes of the resource (of the whole service for services)
     */
    public static function findComposeVolume(iterable $existingNames, string $purpose, string $legacyPrefix): ?string
    {
        $purpose = self::purposeSlug($purpose);

        foreach ($existingNames as $name) {
            $base = preg_replace('/-pr-\d+$/', '', $name);
            foreach (array_unique([$name, $base]) as $candidate) {
                if ($candidate === "{$legacyPrefix}_{$purpose}") {
                    return $candidate;
                }
                if (self::isGenerated($candidate) && Str::afterLast($candidate, '_') === $purpose) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public static function purposeSlug(string $purpose): string
    {
        $slug = Str::slug($purpose, '-');

        return $slug === '' ? 'data' : $slug;
    }

    /**
     * The signed-in user, or for system jobs the owner of the project's team.
     */
    private static function creator(?Project $project): ?User
    {
        $user = Auth::user();
        if ($user instanceof User) {
            return $user;
        }

        return $project?->team?->members()->wherePivot('role', 'owner')->orderBy('users.id')->first();
    }

    private static function slug(string $value, int $length, string $fallback): string
    {
        $slug = trim(substr(Str::slug($value, '-'), 0, $length), '-');

        return $slug === '' ? $fallback : $slug;
    }
}
