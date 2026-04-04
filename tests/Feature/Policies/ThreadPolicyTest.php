<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThreadPolicyTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function social_config_exposes_the_expected_defaults(): void
    {
        $this->assertSame(15, config('social.max_group_participants'));
        $this->assertSame(3, config('social.typing_indicator_ttl_seconds'));
        $this->assertSame(1.5, config('social.typing_throttle_seconds'));
        $this->assertSame(30, config('social.messages_per_page'));
    }

    #[Test]
    public function active_members_can_view_and_send_messages_but_only_admins_can_manage_groups(): void
    {
        $this->ensureSocialThreadTables();

        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $thread = Thread::create([
            'type'             => 'group',
            'name'             => 'Weekend plans',
            'created_by'       => $admin->id,
            'max_participants' => 15,
        ]);

        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id'   => $admin->id,
            'role'      => 'admin',
            'joined_at' => now(),
        ]);

        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id'   => $member->id,
            'role'      => 'member',
            'joined_at' => now(),
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('view', $thread));
        $this->assertTrue(Gate::forUser($admin)->allows('sendMessage', $thread));
        $this->assertTrue(Gate::forUser($admin)->allows('manage', $thread));

        $this->assertTrue(Gate::forUser($member)->allows('view', $thread));
        $this->assertTrue(Gate::forUser($member)->allows('sendMessage', $thread));
        $this->assertFalse(Gate::forUser($member)->allows('manage', $thread));

        $this->assertFalse(Gate::forUser($outsider)->allows('view', $thread));
        $this->assertFalse(Gate::forUser($outsider)->allows('sendMessage', $thread));
        $this->assertFalse(Gate::forUser($outsider)->allows('manage', $thread));
    }

    #[Test]
    public function nobody_can_manage_a_direct_thread(): void
    {
        $this->ensureSocialThreadTables();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $thread = Thread::create([
            'type'             => 'direct',
            'created_by'       => $user->id,
            'max_participants' => 15,
        ]);

        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id'   => $user->id,
            'role'      => 'admin',
            'joined_at' => now(),
        ]);

        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id'   => $otherUser->id,
            'role'      => 'member',
            'joined_at' => now(),
        ]);

        $this->assertFalse(Gate::forUser($user)->allows('manage', $thread));
        $this->assertFalse(Gate::forUser($otherUser)->allows('manage', $thread));
    }

    private function ensureSocialThreadTables(): void
    {
        if (Schema::hasTable('threads')) {
            return;
        }

        foreach ([
            '2026_04_04_000001_create_threads_table.php',
            '2026_04_04_000002_create_thread_participants_table.php',
            '2026_04_04_000003_create_messages_table.php',
            '2026_04_04_000004_create_message_reads_table.php',
            '2026_04_04_000005_create_bill_splits_table.php',
            '2026_04_04_000006_create_bill_split_participants_table.php',
        ] as $migrationFile) {
            $migration = require base_path("database/migrations/{$migrationFile}");
            $migration->up();
        }
    }
}
