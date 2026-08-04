<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Team;
use App\Models\User;
use App\Policies\NotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTeamRoles;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found while chasing this session's next code-review
 * finding: every method in this policy had its real check commented out and unconditionally
 * `return true`, inherited from the original upstream import. Since all 6 notification-settings
 * controllers (Email/Discord/Telegram/Slack/Pushover/Webhook) derive $settings from
 * currentTeam() - never from an attacker-controllable ID - this wasn't a cross-team IDOR, but a
 * real intra-team privilege escalation: any team MEMBER (the lowest role) could view/update
 * secrets (SMTP password, Resend API key, Telegram bot token, webhook URLs) and trigger test
 * sends, despite the commented-out code proving the intended design was owner/admin-only for
 * update()/manage()/sendTest().
 */
class NotificationPolicyTest extends TestCase
{
    use InteractsWithTeamRoles, RefreshDatabase;

    #[Test]
    public function view_allows_any_member_of_the_settings_team(): void
    {
        $team = Team::factory()->create();
        $settings = $team->emailNotificationSettings;
        $policy = new NotificationPolicy;

        $this->assertTrue($policy->view($this->memberOf($team), $settings));
        $this->assertTrue($policy->view($this->adminOf($team), $settings));
    }

    #[Test]
    public function view_denies_a_user_who_does_not_belong_to_the_settings_team(): void
    {
        $team = Team::factory()->create();
        $settings = $team->emailNotificationSettings;
        $outsider = User::factory()->create();
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($outsider, ['role' => 'owner']);

        $this->assertFalse((new NotificationPolicy)->view($outsider, $settings));
    }

    #[Test]
    public function update_denies_a_plain_member(): void
    {
        $team = Team::factory()->create();
        $settings = $team->emailNotificationSettings;

        $this->assertFalse((new NotificationPolicy)->update($this->memberOf($team), $settings));
    }

    #[Test]
    public function update_allows_an_admin_or_owner(): void
    {
        $team = Team::factory()->create();
        $settings = $team->emailNotificationSettings;
        $policy = new NotificationPolicy;

        $this->assertTrue($policy->update($this->adminOf($team), $settings));
        $this->assertTrue($policy->update($this->ownerOf($team), $settings));
    }

    #[Test]
    public function manage_and_send_test_follow_the_same_rule_as_update(): void
    {
        $team = Team::factory()->create();
        $settings = $team->emailNotificationSettings;
        $policy = new NotificationPolicy;
        $member = $this->memberOf($team);
        $admin = $this->adminOf($team);

        $this->assertFalse($policy->manage($member, $settings));
        $this->assertFalse($policy->sendTest($member, $settings));
        $this->assertTrue($policy->manage($admin, $settings));
        $this->assertTrue($policy->sendTest($admin, $settings));
    }
}
