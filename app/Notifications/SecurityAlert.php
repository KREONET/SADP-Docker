<?php

namespace App\Notifications;

use App\Models\AuditEvent;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use BackedEnum;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Stringable;

/**
 * Security-relevant event sent to the platform administrators (root team).
 */
class SecurityAlert extends CustomEmailNotification
{
    public const EVENT = 'security_alert';

    /**
     * @var array<string, string>
     */
    public array $details;

    public string $occurredAt;

    /**
     * @param  array<string, mixed>  $context  Non-secret details. Secret-looking keys are redacted again here.
     */
    public function __construct(public string $type, public string $title, array $context = [])
    {
        $this->onQueue('high');
        $this->details = self::normalizeDetails($context);
        $this->occurredAt = now()->toIso8601String();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels(self::EVENT);
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject(__('Coolify security alert: :title', ['title' => $this->translatedTitle()]));
        $mail->view('emails.security-alert', [
            'title' => $this->translatedTitle(),
            'type' => $this->type,
            'details' => $this->details,
            'occurredAt' => $this->occurredAt,
            'auditLogUrl' => $this->auditLogUrl(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':rotating_light: '.__('Security alert'),
            description: $this->translatedTitle(),
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );

        $message->addField(__('Type'), $this->type, true);
        foreach ($this->details as $key => $value) {
            $message->addField(Str::headline($key), $value, true);
        }
        $message->addField(__('Audit log'), '[Link]('.$this->auditLogUrl().')');

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => __('Coolify security alert: :title', ['title' => $this->translatedTitle()])."\n\n".$this->plainDetails(),
            'buttons' => [
                [
                    'text' => __('Audit log'),
                    'url' => $this->auditLogUrl(),
                ],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: __('Security alert'),
            level: 'error',
            message: $this->translatedTitle()."<br/>\n".nl2br(e($this->plainDetails())),
            buttons: [
                __('Audit log') => $this->auditLogUrl(),
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: __('Security alert'),
            description: $this->translatedTitle()."\n\n".$this->plainDetails()."\n\n".__('Audit log').': '.$this->auditLogUrl(),
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => $this->title,
            'event' => self::EVENT,
            'alert_type' => $this->type,
            'details' => $this->details,
            'occurred_at' => $this->occurredAt,
            'url' => $this->auditLogUrl(),
        ];
    }

    private function translatedTitle(): string
    {
        return __($this->title);
    }

    private function plainDetails(): string
    {
        return collect(['type' => $this->type] + $this->details)
            ->map(fn (string $value, string $key): string => Str::headline($key).': '.$value)
            ->implode("\n");
    }

    private function auditLogUrl(): string
    {
        return base_url().'/team/audit-log';
    }

    /**
     * Flatten the context to short strings so the queued payload stays small and serializable.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private static function normalizeDetails(array $context): array
    {
        $details = [];

        foreach (AuditEvent::redactContext($context) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $text = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                $value instanceof BackedEnum => (string) $value->value,
                $value instanceof Stringable => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };

            $details[(string) $key] = Str::limit($text, 500);
        }

        return $details;
    }
}
