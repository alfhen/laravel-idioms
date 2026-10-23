<?php

namespace Tests\Feature;

use App\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_accepted_offers(): void
    {
        Offer::factory()->create(['title' => 'Open']);
        Offer::factory()->accepted()->create(['title' => 'Taken']);

        $this->getJson('/api/offers?accepted=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Taken')
            ->assertJsonPath('data.0.accepted', true);
    }

    public function test_titles_list_only_titles(): void
    {
        Offer::factory()->accepted()->create(['title' => 'Bravo']);
        Offer::factory()->create(['title' => 'Alpha']);

        $this->getJson('/api/offers/titles')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Alpha')
            ->assertJsonPath('data.0.accepted', false)
            ->assertJsonMissingPath('data.0.price_cents');
    }

    public function test_store_normalises_tags(): void
    {
        $this->postJson('/api/offers', [
            'title' => 'Yarn bundle',
            'price_cents' => 4900,
            'tags' => ['0', '00', ' wool ', 'wool'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.tags', ['0', '00', 'wool']);
    }

    public function test_store_validates_input(): void
    {
        $this->postJson('/api/offers', ['price_cents' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'price_cents']);
    }
}
