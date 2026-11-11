<?php

namespace App\Services;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Hardened password reset flow:
 *  - random 64-char tokens, stored only as SHA-256 hashes
 *  - single use (used_at) and expiring (30 min default)
 *  - identical response whether or not the email exists (no enumeration)
 *  - all existing sessions/tokens are revoked after a successful reset
 */
class PasswordResetService
{
    public function __construct(private AuditLogger $audit) {}

    /** Creates a reset token and emails it. Returns nothing sensitive. */
    public function sendResetLink(string $actorType, string $email): void
    {
        $account = $this->findAccount($actorType, $email);

        if ($account === null) {
            // Do not reveal account existence — still log the attempt server-side.
            $this->audit->log('auth.password_reset_requested', [
                'actor_type' => 'system',
                'context'    => ['actor_type_requested' => $actorType, 'found' => false],
            ]);
            return;
        }

        // Invalidate all previous unused tokens for this account.
        DB::table('password_reset_tokens')
            ->where('actor_type', $actorType)
            ->where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(64);
        $ttl   = config('pos.passwords.reset_token_ttl_minutes');

        DB::table('password_reset_tokens')->insert([
            'actor_type' => $actorType,
            'email'      => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttl),
            'used_at'    => null,
            'created_at' => now(),
        ]);

        try {
            Mail::raw(
                "A password reset was requested for your account.\n\nReset code: {$token}\n\nIt expires in {$ttl} minutes and can be used once. If you did not request this, ignore this email.",
                fn ($message) => $message->to($email)->subject(config('pos.platform_name').' password reset')
            );
        } catch (\Throwable $e) {
            \Log::error('Password reset email failed', ['error' => $e->getMessage()]);
        }

        $this->audit->log('auth.password_reset_requested', [
            'actor'  => $account,
            'context'=> ['actor_type_requested' => $actorType, 'found' => true],
        ]);

        // In local/dev (no real SMTP), surface the token in the app log so the
        // flow can be exercised end-to-end.
        if (config('mail.default') === 'log') {
            \Log::info('Password reset token issued', ['email' => $email, 'token' => $token]);
        }
    }

    /**
     * @return bool Whether the reset succeeded. Generic error otherwise —
     *              callers must not distinguish "bad token" vs "bad email".
     */
    public function reset(string $actorType, string $email, string $token, string $newPassword): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('actor_type', $actorType)
            ->where('email', $email)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($record === null) {
            $this->audit->log('auth.password_reset_failed', [
                'actor_type' => 'system',
                'context'    => ['actor_type_requested' => $actorType],
            ]);
            return false;
        }

        $account = $this->findAccount($actorType, $email);

        if ($account === null) {
            return false;
        }

        DB::transaction(function () use ($record, $account, $newPassword) {
            // Single-use: consume the token inside the same transaction.
            $consumed = DB::table('password_reset_tokens')
                ->where('id', $record->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            if ($consumed !== 1) {
                throw new \RuntimeException('Reset token already used.');
            }

            $account->forceFill([
                'password'            => $newPassword, // cast hashes (Argon2id)
                'password_changed_at' => now(),
            ])->save();

            // Invalidate ALL existing sessions/tokens for the account.
            $account->tokens()->delete();
        });

        $this->audit->log('auth.password_reset_completed', [
            'actor'   => $account,
            'subject' => $account,
        ]);

        return true;
    }

    private function findAccount(string $actorType, string $email): User|SuperAdmin|null
    {
        return match ($actorType) {
            'super_admin' => SuperAdmin::where('email', $email)->first(),
            default       => User::where('email', $email)->first(),
        };
    }
}
