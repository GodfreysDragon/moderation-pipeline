<?php

namespace Tests\Feature;

use App\Enums\DecisionSource;
use App\Enums\Outcome;
use App\Enums\TaskKind;
use App\Models\ReviewTask;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_members_are_sent_to_their_submissions()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('submissions.index'));
    }

    public function test_staff_see_pipeline_metrics()
    {
        Submission::factory()->count(3)->scored()->decided(Outcome::Approve)->create();
        Submission::factory()->count(2)->scored(['spam' => 0.95])->decided(Outcome::Reject, reason: 'spam')->create();
        $human = Submission::factory()->scored(['hate' => 0.6])->decided(Outcome::Reject, DecisionSource::Moderator, 'hate')->create();
        ReviewTask::factory()->for($human)->create(['decision' => 'reject', 'resolved_at' => now(), 'claimed_at' => now()->subMinute()]);
        $audited = Submission::factory()->decided(Outcome::Approve)->create();
        ReviewTask::factory()->for($audited)->kind(TaskKind::Audit, Outcome::Approve)->create(['decision' => 'approve', 'resolved_at' => now()]);
        ReviewTask::factory()->create();

        $this->actingAs(User::factory()->moderator()->create())
            ->get(route('dashboard', ['days' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('metrics.days', 7)
                ->where('metrics.totals.submitted', 8)
                ->where('metrics.automation_rate', 0.8571)
                ->where('metrics.audit.approve.precision', 1)
                ->where('metrics.queue.total', 1)
                ->has('metrics.daily', 7)
                ->where('metrics.categories.0.category', 'spam')
                ->where('metrics.categories.0.auto', 2)
                ->has('metrics.stages', 4)
            );
    }
}
