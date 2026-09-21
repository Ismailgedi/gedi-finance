<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(private readonly string $url)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your Gedi Finance password')
            ->greeting('Gedi Finance')
            ->line('Reset your password')
            ->line('We received a request to reset your Gedi Finance password.')
            ->action('Reset Password', $this->url)
            ->line('This link expires after 60 minutes and can only be used once.')
            ->line('If you did not request this reset, you can safely ignore this email.');
    }

    public function resetUrl(): string
    {
        return $this->url;
    }
}
