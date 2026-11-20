<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Models\StandalonePostgresql;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandalonePostgresql $database;

    protected function databaseLabel(): string
    {
        return 'Postgres';
    }

    protected function sslModeOptions(): array
    {
        return [
            'allow' => ['title' => __('Allow insecure connections'), 'label' => __(':mode (insecure)', ['mode' => 'allow'])],
            'prefer' => ['title' => __('Prefer secure connections'), 'label' => __(':mode (secure)', ['mode' => 'prefer'])],
            'require' => ['title' => __('Require secure connections'), 'label' => __(':mode (secure)', ['mode' => 'require'])],
            'verify-ca' => ['title' => __('Verify CA certificate'), 'label' => __(':mode (secure)', ['mode' => 'verify-ca'])],
            'verify-full' => ['title' => __('Verify full certificate'), 'label' => __(':mode (secure)', ['mode' => 'verify-full'])],
        ];
    }

    protected function sslModeHelper(): string
    {
        return __('Choose the SSL verification mode for :engine connections', ['engine' => 'PostgreSQL']);
    }

    protected function afterRefresh(): void
    {
        $this->sslMode = $this->database->ssl_mode;
    }

    protected function applyExtraSslAttributes(): void
    {
        $this->database->ssl_mode = $this->sslMode;
    }

    public function updatedSslMode(): void
    {
        $this->instantSaveSSL();
    }
}
