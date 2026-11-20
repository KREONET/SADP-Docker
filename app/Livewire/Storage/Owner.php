<?php

namespace App\Livewire\Storage;

use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Models\S3Storage;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lets platform administrators assign the owner of an S3 storage. Storages created before
 * owners were recorded have none and are visible to platform administrators only.
 */
class Owner extends Component
{
    use AuthorizesPlatformAdmin;

    #[Locked]
    public int $storageId;

    public ?int $selectedUserId = null;

    public function mount(S3Storage $storage): void
    {
        $this->authorizePlatformAdmin();

        $this->storageId = $storage->id;
        $this->selectedUserId = $storage->user_id;
    }

    /**
     * Assign the selected team member as the owner after the administrator confirmed their password.
     */
    public function assignOwner(mixed $password = ''): bool|string
    {
        $this->authorizePlatformAdmin();
        $storage = $this->storage();

        $owner = $this->candidates($storage)->firstWhere('id', $this->selectedUserId);
        if (! $owner instanceof User) {
            return __('Choose a member of the storage team.');
        }

        $context = [
            's3_storage_uuid' => $storage->uuid,
            'team_id' => $storage->team_id,
            'previous_user_id' => $storage->user_id,
            'user_id_assigned' => $owner->id,
        ];

        if (! verifyPasswordConfirmation($password, $this)) {
            auditLog('s3_storage.owner.assigned', $context + ['result' => 'denied', 'reason' => 'password_confirmation_failed'], 'warning');

            return __('The provided password is incorrect.');
        }

        $storage->forceFill(['user_id' => $owner->id])->save();
        auditLog('s3_storage.owner.assigned', $context + ['result' => 'success'], 'warning');
        $this->dispatch('success', __('Owner changed to :email.', ['email' => $owner->email]));

        return true;
    }

    public function render(): View
    {
        $storage = $this->storage();

        return view('livewire.storage.owner', [
            'storage' => $storage,
            'owner' => $storage->user_id === null ? null : User::query()->find($storage->user_id),
            'candidates' => $this->candidates($storage),
        ]);
    }

    private function storage(): S3Storage
    {
        return S3Storage::query()->findOrFail($this->storageId);
    }

    /**
     * Members of the team the storage belongs to.
     *
     * @return Collection<int, User>
     */
    private function candidates(S3Storage $storage): Collection
    {
        return $storage->team?->members()->orderBy('users.email')->get() ?? collect();
    }
}
