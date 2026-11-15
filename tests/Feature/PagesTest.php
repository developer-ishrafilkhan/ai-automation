<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_app_page_renders()
    {
        $this->actingAs(User::factory()->create());

        $this->post('/workflows', ['name' => 'Weekly reel'])->assertRedirect();
        $workflowId = Workflow::first()->id;

        $this->get("/workflows/{$workflowId}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workflows/editor')
            ->where('workflow.name', 'Weekly reel')
            ->where('workflow.draft_graph.nodes.0.type', 'trigger.manual'));
        $this->get('/workflows')->assertOk()->assertInertia(fn (Assert $page) => $page->component('workflows/index')->has('workflows', 1));
        $this->get('/keys')->assertOk()->assertInertia(fn (Assert $page) => $page->component('credentials/index'));
        $this->get('/runs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('executions/index'));
        $this->get('/assets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('assets/index'));
    }
}
