<?php

declare(strict_types=1);

return [
    'common' => [
        'center_account' => 'Center account',
    ],
    'fields' => [
        'email_or_phone' => 'Email or phone',
        'email' => 'Email address',
        'password' => 'Password',
        'new_password' => 'New password',
        'confirm_new_password' => 'Confirm new password',
        'center_name' => 'Center name',
        'your_name' => 'Your name',
        'center_address' => 'Center address',
        'language' => 'Language',
    ],
    'actions' => [
        'sign_in' => 'Sign in',
        'forgot_password' => 'Forgot password?',
        'create_center' => 'Create a center',
        'send_reset_link' => 'Send reset link',
        'return_to_sign_in' => 'Return to sign in',
        'update_password' => 'Update password',
        'create_account' => 'Create account',
        'working' => 'Working…',
        'resend_verification' => 'Resend verification email',
        'try_again' => 'Try again',
        'start_over' => 'Start over',
    ],
    'login' => [
        'title' => 'Sign in',
        'new_here' => 'New here?',
    ],
    'forgot' => [
        'title' => 'Forgot password',
        'sent' => 'If an active account matches that email, a secure reset link has been sent.',
    ],
    'reset' => [
        'title' => 'Set a new password',
        'updated' => 'Password updated. Sign in with your new password.',
        'rules' => 'At least 10 characters, with letters and numbers.',
    ],
    'registration' => [
        'title' => 'Create your center',
        'subtitle' => 'Five details now. Everything else once you are in.',
        'slug_help' => 'Lowercase letters, numbers, and hyphens. This becomes your permanent center address.',
        'slug_unavailable' => 'This center address is unavailable.',
        'choose_plan' => 'Choose a plan',
        'existing_center' => 'Already have a center? Open its dedicated subdomain to sign in.',
        'billing_cycle' => 'Billing cycle',
        'cycle_monthly' => 'Monthly',
        'cycle_yearly' => 'Yearly',
    ],
    'status' => [
        'title' => 'Setting up your center',
        'check_inbox' => 'Check your inbox before we create the center.',
        'verification_sent' => 'We sent a secure, expiring verification link to the registration email.',
        'preparing_description' => 'Creating your database and applying the schema. This takes a few seconds.',
        'preparing' => 'Preparing…',
        'ready' => 'Your center is ready.',
        'failed' => 'Something went wrong while setting up your center.',
        'retry_help' => 'You can pick up where it stopped. Your password and details are unchanged.',
        'expired' => 'This registration has expired and can no longer be resumed.',
        'closed' => 'This registration is closed.',
        'closed_help' => 'It was not completed in time and can no longer be resumed.',
        'not_found' => 'We could not find that registration.',
        'retrying' => 'Trying again…',
        'retry_unavailable' => 'This registration can no longer be retried. Please register again.',
        'verification_resent' => 'A new verification email has been sent.',
        'verification_resend_failed' => 'The verification email could not be resent.',
    ],
    'errors' => [
        'credentials' => 'Those credentials do not match our records.',
        'throttled' => 'Too many attempts. Please wait and try again.',
        'reset_failed' => 'This password reset link is invalid or has expired.',
    ],
    'billing_periods' => [
        'monthly' => 'month',
        'yearly' => 'year',
    ],
];
