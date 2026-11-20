<?php

namespace App\Livewire\Project\Database\Mysql;

use App\Models\StandaloneMysql;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneMysql $database;

    protected function databaseLabel(): string
    {
        return 'MySQL';
    }

    protected function sslModeOptions(): array
    {
        return [
            'PREFERRED' => ['title' => __('Prefer secure connections'), 'label' => __(':mode (secure)', ['mode' => 'Prefer'])],
            'REQUIRED' => ['title' => __('Require secure connections'), 'label' => __(':mode (secure)', ['mode' => 'Require'])],
            'VERIFY_CA' => ['title' => __('Verify CA certificate'), 'label' => __(':mode (secure)', ['mode' => 'Verify CA'])],
            'VERIFY_IDENTITY' => ['title' => __('Verify full certificate'), 'label' => __(':mode (secure)', ['mode' => 'Verify Full'])],
        ];
    }

    protected function sslModeHelper(): string
    {
        return __('Choose the SSL verification mode for :engine connections', ['engine' => 'MySQL']);
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
