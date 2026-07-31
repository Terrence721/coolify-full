<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\User;

use App\Actions\User\DeleteUserTeams;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found 2026-07-31 (code review, issue #70):
 * getTeamsPreview() read $this->user->teams (a cached Eloquent relation property), so calling it
 * twice on the same action instance - as AdminDeleteUser does, once to print a preview and again
 * inside execute() as a safety re-check after an interactive confirmation prompt - returned the
 * exact same stale snapshot both times, never reflecting DB changes made during the pause. Fixed
 * by querying fresh ($this->user->teams()->get()) on every call.
 *
 * User::boot()'s created() hook auto-creates a personal team (the user as sole owner) for every
 * factory-created user, so assertions below key off the specific shared $team's id rather than
 * blanket empty()/count() checks against the whole preview.
 */
class DeleteUserTeamsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_second_preview_call_reflects_membership_changes_made_after_the_first_call(): void
    {
        $userBeingDeleted = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($userBeingDeleted, ['role' => 'owner']);
        $team->members()->attach($otherAdmin, ['role' => 'admin']);

        $action = new DeleteUserTeams($userBeingDeleted);

        // First call (the "print a preview" call in AdminDeleteUser): the sole owner has another
        // admin available, so the shared team is classified for ownership transfer.
        $firstPreview = $action->getTeamsPreview();
        $this->assertTrue($firstPreview['to_transfer']->pluck('team.id')->contains($team->id));
        $this->assertFalse($firstPreview['to_delete']->pluck('id')->contains($team->id));

        // Simulates a real DB change happening during the interactive confirmation pause between
        // the preview and execute() in AdminDeleteUser: the other admin leaves the team, so the
        // user being deleted is now the team's only member.
        $team->members()->detach($otherAdmin);

        // Second call on the SAME action instance (what execute() does internally as its safety
        // re-check) must reflect the new reality - the team should now be classified for deletion,
        // not a transfer to someone no longer on the team.
        $secondPreview = $action->getTeamsPreview();
        $this->assertTrue($secondPreview['to_delete']->pluck('id')->contains($team->id));
        $this->assertFalse($secondPreview['to_transfer']->pluck('team.id')->contains($team->id));
    }

    #[Test]
    public function execute_deletes_a_team_that_became_sole_ownership_only_after_the_initial_preview(): void
    {
        $userBeingDeleted = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($userBeingDeleted, ['role' => 'owner']);
        $team->members()->attach($otherAdmin, ['role' => 'admin']);

        $action = new DeleteUserTeams($userBeingDeleted);
        $action->getTeamsPreview();

        $team->members()->detach($otherAdmin);

        $action->execute();

        $this->assertNull(Team::find($team->id));
    }
}
