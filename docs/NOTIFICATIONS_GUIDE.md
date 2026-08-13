# Notifications Guide

This guide explains how to use the notification system in Fynix Cyber Audit.

## Overview

Fynix Cyber Audit now has two types of notifications:

1. **Filament Toast Notifications** - Temporary, session-based notifications using `Notification::make()`
2. **Database Notifications** - Persistent notifications stored in the database with a bell icon in the topbar

## Filament Toast Notifications (Existing)

These are temporary notifications that appear as toast messages and disappear after a few seconds.

### Usage Example

```php
use Filament\Notifications\Notification;

// Simple notification
Notification::make()
    ->title('Saved successfully')
    ->success()
    ->send();

// With more details
Notification::make()
    ->title('Operation completed')
    ->body('The audit has been updated successfully.')
    ->icon('heroicon-o-check-circle')
    ->iconColor('success')
    ->send();
```

## Database Notifications (New)

These are persistent notifications that are stored in the database and appear in the bell icon dropdown in the topbar.

### Usage Example

```php
use App\Notifications\DropdownNotification;

// Send a database notification to a user
$user = auth()->user();
$user->notify(new DropdownNotification(
    title: 'New Audit Assignment',
    body: 'You have been assigned to Audit #123',
    icon: 'heroicon-o-clipboard-document-check',
    color: 'info'
));
```

### Creating Custom Notification Classes

1. Create a new notification class:

```bash
php artisan make:notification YourNotificationName
```

2. Implement the notification:

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class YourNotificationName extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $icon = null,
        public ?string $color = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon ?? 'heroicon-o-bell',
            'color' => $this->color ?? 'gray',
        ];
    }
}
```

3. Send the notification:

```php
$user->notify(new YourNotificationName(
    title: 'Important Update',
    body: 'Something happened that you should know about.',
    icon: 'heroicon-o-exclamation-triangle',
    color: 'warning'
));
```

### Combining Both Types

You can send both a toast notification (for immediate feedback) and a database notification (for persistent record):

```php
use Filament\Notifications\Notification as FilamentNotification;
use App\Notifications\DropdownNotification;

// Send toast notification
FilamentNotification::make()
    ->title('Audit assigned')
    ->success()
    ->send();

// Send database notification
$user->notify(new DropdownNotification(
    title: 'New Audit Assignment',
    body: 'You have been assigned to Audit #123',
    icon: 'heroicon-o-clipboard-document-check',
    color: 'success'
));
```

## Available Icons

Use any Heroicon from the [Heroicons library](https://heroicons.com/):
- `heroicon-o-bell`
- `heroicon-o-check-circle`
- `heroicon-o-exclamation-triangle`
- `heroicon-o-information-circle`
- `heroicon-o-x-circle`
- `heroicon-o-clipboard-document-check`
- etc.

## Available Colors

- `primary`
- `success`
- `warning`
- `danger`
- `info`
- `gray`

## Features

- **Bell icon** in the topbar shows unread notification count
- **Red badge** displays the number of unread notifications
- **Dropdown menu** shows the 10 most recent notifications
- **Mark as read** - Individual notifications can be marked as read
- **Mark all as read** - Button to mark all notifications as read
- **Delete** - Remove individual notifications
- **Timestamps** - Shows relative time (e.g., "2 minutes ago")
- **Read/Unread states** - Unread notifications are highlighted

## Testing

To test the notification system, you can use Tinker:

```bash
php artisan tinker
```

Then run:

```php
$user = \App\Models\User::first();
$user->notify(new \App\Notifications\DropdownNotification(
    title: 'Test Notification',
    body: 'This is a test notification to see if everything works!',
    icon: 'heroicon-o-bell',
    color: 'info'
));
```

Refresh your browser and you should see the bell icon with a red badge indicating 1 unread notification.
