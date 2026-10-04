<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RuntimeException;

/**
 * A Forgejo (or Gitea) server that platform admins registered as a git source.
 * Every team can pick it when creating an application; only platform admins manage it.
 * The access token belongs to a service account that can read the repositories to deploy.
 *
 * [한국어] 플랫폼 관리자가 등록한 Forgejo(또는 Gitea) 서버 하나를 나타냅니다.
 * 등록은 플랫폼 관리자만, 사용(저장소 선택·배포)은 모든 팀이 합니다. 토큰은 암호화되어 저장됩니다.
 * 화면: App\Livewire\Platform\GitSources, 저장소 선택: App\Livewire\Project\New\ForgejoPrivateRepository
 */
class ForgejoApp extends BaseModel
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'html_url',
        'login',
        'access_token',
        'is_system_wide',
        'team_id',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'is_system_wide' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (ForgejoApp $source): void {
            if ($source->applications()->count() > 0) {
                throw new RuntimeException('This source is being used by an application. Please delete all applications first.');
            }
        });
    }

    /**
     * Sources a team can deploy from: its own and the instance-wide ones.
     */
    public static function ownedByCurrentTeam()
    {
        $teamId = currentTeam()?->id;

        return static::query()->where(function ($query) use ($teamId): void {
            // Without a current team only the instance-wide servers are visible.
            $query->when($teamId !== null, fn ($query) => $query->where('team_id', $teamId))
                ->orWhere('is_system_wide', true);
        });
    }

    public function applications(): MorphMany
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function apiUrlBase(): string
    {
        return rtrim($this->html_url, '/').'/api/v1';
    }
}
