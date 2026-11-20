<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\TransactionalEmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * Sent to the previous address after a user changed their sign-in email, so the
 * owner of that mailbox notices a change they did not make.
 */
class EmailAddressChanged extends CustomEmailNotification
{
    /**
     * Recipient override. TransactionalEmailChannel sends to `newEmail` when it is
     * set; for this notification it carries the PREVIOUS address, because the user's
     * own email already points to the new one.
     */
    public string $newEmail;

    public function via(): array
    {
        return [TransactionalEmailChannel::class];
    }

    public function __construct(
        public string $previousEmail,
        public Carbon $changedAt,
        public bool $isTransactionalEmail = true
    ) {
        $this->newEmail = $previousEmail;
        $this->onQueue('high');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject(__(':app: Your account email address was changed', ['app' => brand_name()]));
        $mail->view('emails.email-address-changed', [
            'previousEmail' => $this->previousEmail,
            'changedAt' => $this->changedAt,
        ]);

        return $mail;
    }
}
