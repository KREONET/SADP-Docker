<div wire:poll.10000ms="refreshStatus" class="flex items-center gap-1">
    <x-status-summary :status="$database->status" :title="__('Database status')" :healthcheck-url="$healthcheckUrl" />
    <x-application.restart-limit-warning :application="$database" />
</div>
