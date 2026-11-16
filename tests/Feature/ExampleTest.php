<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guests_hitting_the_root_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
