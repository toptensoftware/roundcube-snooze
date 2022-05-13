<?php

// The mailbox to move snoozed emails to
// This must match the mailbox name used by the python wake script
// (see snooze.py)
$config['snooze_mbox'] = 'Snoozed';

// The time for automatically calculated morning snooze times
$config['snooze_morning'] = "08:00";

// The time for automatically calculated evening snooze times
$config['snooze_evening'] = "18:00";
