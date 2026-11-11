<?php

use Illuminate\Support\Facades\Schedule;

// Daily lifecycle engine: reminders (14/7/3/1), expiry → grace, grace → suspend.
Schedule::command('subscriptions:monitor')->dailyAt('01:15');
