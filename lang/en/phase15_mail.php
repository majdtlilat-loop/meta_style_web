<?php

declare(strict_types=1);

return [
    'verification' => [
        'subject' => 'Verify your Meta Style email',
        'title' => 'Verify your email',
        'greeting' => 'Hello :name, confirm this email address to create :center.',
        'action' => 'Verify email',
        'expires' => 'This secure link expires at :time.',
        'ignore' => 'If you did not request this account, you can ignore this message.',
    ],
    'created' => [
        'subject' => 'Your Meta Style center is ready',
        'title' => 'Your center is ready',
        'greeting' => 'Hello :name, :center was created successfully.',
        'login_email' => 'Login email',
        'plan' => 'Plan',
        'public_website' => 'Public website',
        'staff_login' => 'Manager and staff login',
        'action' => 'Set up your center',
        'no_password' => 'For your security, this email never contains your password.',
    ],
    'password_reset' => [
        'subject' => 'Reset your Meta Style center password',
        'title' => 'Reset your Meta Style password',
        'greeting' => 'Hello :name,',
        'request' => 'We received a password reset request for your account at :center.',
        'action' => 'Set a new password',
        'ignore' => 'This secure link expires and works once. If you did not request it, you can ignore this email.',
        'no_password' => 'Meta Style will never ask you to send your password by email.',
    ],
    'invited' => [
        'subject' => 'Your Meta Style center :center is ready',
        'title' => ':center is ready',
        'greeting' => 'Hello :name, Meta Style created :center for you.',
        'action' => 'Set your password',
        'expiry' => 'The link works once and expires in 72 hours.',
    ],
    'access_link' => [
        'subject' => 'Set a new password for :center',
        'title' => 'Set a new password',
        'greeting' => 'Hello :name, Meta Style support sent you a link to set a new password for :center.',
        'action' => 'Set a new password',
        'expiry' => 'The link works once and expires in 24 hours.',
    ],
    'platform_access' => [
        'invite_subject' => 'You have been added to Meta Style',
        'reset_subject' => 'Reset your Meta Style password',
        'invite_title' => 'Welcome to the Meta Style team',
        'reset_title' => 'Reset your password',
        'greeting' => 'Hello :name,',
        'invite_body' => 'You now have a platform account. Choose your password to sign in.',
        'reset_body' => 'Use the button below to choose a new password.',
        'action' => 'Choose a password',
        'expiry' => 'The link works once. If you did not expect this email, you can ignore it.',
    ],
];
