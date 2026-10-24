<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_can_invite_and_the_link_is_mailed(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $team = Team::factory()->withMember($admin, TeamRole::Admin)->create();

        $this->actingAs($admin)
            ->post(route('teams.invitations.store', $team), ['email' => 'New@Example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('invitation_url');

        $invitation = $team->invitations()->sole();
        $this->assertSame('new@example.com', $invitation->email);
        $this->assertSame(TeamRole::Viewer, $invitation->role);
        Mail::assertSent(TeamInvitationMail::class, fn (TeamInvitationMail $mail) => $mail->hasTo('new@example.com'));
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->withMember($owner, TeamRole::Owner)->create();

        $url = $this->actingAs($owner)
            ->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'admin'])
            ->getSession()->get('invitation_url');

        $token = basename($url);
        $this->assertDatabaseMissing('team_invitations', ['token_hash' => $token]);
        $this->assertTrue(TeamInvitation::findByToken($token)->team->is($team));
    }

    public function test_a_new_invitation_replaces_the_previous_one_for_the_same_address(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->withMember($owner, TeamRole::Owner)->create();

        $this->actingAs($owner)->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'viewer']);
        $this->actingAs($owner)->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'admin']);

        $this->assertSame(TeamRole::Admin, $team->invitations()->sole()->role);
    }

    public function test_viewers_cannot_invite(): void
    {
        $viewer = User::factory()->create();
        $team = Team::factory()->withMember($viewer, TeamRole::Viewer)->create();

        $this->actingAs($viewer)
            ->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'viewer'])
            ->assertForbidden();
    }

    public function test_admins_cannot_invite_owners(): void
    {
        $admin = User::factory()->create();
        $team = Team::factory()->withMember($admin, TeamRole::Admin)->create();

        $this->actingAs($admin)
            ->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'owner'])
            ->assertSessionHasErrors('role');
    }

    public function test_existing_members_cannot_be_invited(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->withMember($owner, TeamRole::Owner)->create();

        $this->actingAs($owner)
            ->post(route('teams.invitations.store', $team), ['email' => $owner->email, 'role' => 'viewer'])
            ->assertSessionHasErrors('email');
    }

    public function test_the_invited_user_can_accept_and_joins_with_the_role(): void
    {
        $user = User::factory()->withTeam()->create(['email' => 'new@example.com']);
        $invitation = TeamInvitation::factory()->withToken('secret-token')->create([
            'email' => 'new@example.com',
            'role' => TeamRole::Admin,
        ]);

        $this->actingAs($user)
            ->post(route('invitations.accept', 'secret-token'))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(TeamRole::Admin, $user->roleIn($invitation->team));
        $this->assertTrue($user->fresh()->currentTeam->is($invitation->team));
        $this->assertModelMissing($invitation);
    }

    public function test_another_user_cannot_accept_the_invitation(): void
    {
        $intruder = User::factory()->withTeam()->create();
        $invitation = TeamInvitation::factory()->withToken('secret-token')->create(['email' => 'new@example.com']);

        $this->actingAs($intruder)
            ->post(route('invitations.accept', 'secret-token'))
            ->assertForbidden();

        $this->assertFalse($invitation->team->hasMember($intruder));
    }

    public function test_expired_invitations_cannot_be_accepted(): void
    {
        $user = User::factory()->withTeam()->create(['email' => 'new@example.com']);
        TeamInvitation::factory()->withToken('secret-token')->expired()->create(['email' => 'new@example.com']);

        $this->actingAs($user)
            ->post(route('invitations.accept', 'secret-token'))
            ->assertNotFound();
    }

    public function test_a_guest_is_sent_back_to_the_invitation_after_registering(): void
    {
        $invitation = TeamInvitation::factory()->withToken('secret-token')->create(['email' => 'new@example.com']);

        $this->get(route('invitations.show', 'secret-token'))
            ->assertOk()
            ->assertSee($invitation->team->name);

        $this->post(route('register'), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect(route('invitations.show', 'secret-token'));
    }

    public function test_admins_can_cancel_an_invitation_of_their_own_team_only(): void
    {
        $admin = User::factory()->create();
        $team = Team::factory()->withMember($admin, TeamRole::Admin)->create();
        $ownInvitation = TeamInvitation::factory()->for($team)->create();
        $otherInvitation = TeamInvitation::factory()->create();

        $this->actingAs($admin)
            ->delete(route('teams.invitations.destroy', [$team, $otherInvitation]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(route('teams.invitations.destroy', [$team, $ownInvitation]))
            ->assertRedirect();

        $this->assertModelMissing($ownInvitation);
        $this->assertModelExists($otherInvitation);
    }
}
