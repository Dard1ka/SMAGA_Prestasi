<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * Base for the Admin-area account notices (weights, signature, Kepala Sekolah replacement, role changes, archive run):
 * queued mail (queue `mail`) + database row for the bell (shape: StaffNotice). Short Indonesian text, no personal data
 * beyond staff names. Mail is skipped automatically for accounts without an email. Being an AdminNotice is what lets a
 * mail reach an address nobody verified (User::routeNotificationForMail(), D-68), so work mail with a lomba name or a
 * free-text reason extends StaffNotice instead (QA10: LetterRevokedNotice).
 */
abstract class AdminNotice extends StaffNotice {}
