<?php

namespace App\Services\Auth;

/**
 * Resolve a pending login.id visit to /two-factor/challenge.
 */
enum ChallengeMountOutcome: string
{
    case ShowForm = 'show';
    case Login = 'login';
    case Dashboard = 'dashboard';
    case TwoFactorSetup = 'two-factor.setup';
}
