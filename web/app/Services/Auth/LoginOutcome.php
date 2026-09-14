<?php

namespace App\Services\Auth;

/**
 * Where the password step should send the operator next.
 */
enum LoginOutcome: string
{
    case Dashboard = 'dashboard';
    case TwoFactorChallenge = 'two-factor.challenge';
    case TwoFactorSetup = 'two-factor.setup';
}
