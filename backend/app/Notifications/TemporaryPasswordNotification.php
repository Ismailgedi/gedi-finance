<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a Super-Admin-generated temporary password to the affected user.
 *
 * This is best-effort and optional: the temporary password is always
 * returned in the admin API response and shown in the "Reset Password"
 * modal, so the account-management flow works whether or not this email
 * actually gets delivered. See Admin\UserController for the send site and
 * why it is wrapped defensively.
 */
class TemporaryPasswordNotification extends Notification
{
    public function __construct(
        private readonly string $temporaryPassword,
        private readonly string $loginUrl,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Gedi Finance password was reset')
            ->greeting('Gedi Finance')
            ->line('An administrator reset your Gedi Finance account password.')
            ->line('Your temporary password is: ' . $this->temporaryPassword)
            ->action('Sign in', $this->loginUrl)
            ->line('You will be required to choose a new password the first time you sign in with it.')
            ->line('If you did not expect this, contact your administrator immediately.');
    }
}
