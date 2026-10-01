<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_the_blacklist_tool()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect('/blacklist');
    }
}
