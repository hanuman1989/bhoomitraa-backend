<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['title' => 'Heritage Tours', 'description' => 'Discover royal forts and timeless palaces.', 'status' => true],
            ['title' => 'Luxury Escapes', 'description' => 'Exceptional stays and curated experiences.', 'status' => true],
            ['title' => 'Adventure Trips', 'description' => 'Trekking, rafting and outdoor adventures.', 'status' => true],
            ['title' => 'Wildlife Safaris', 'description' => 'Explore national parks and wildlife.', 'status' => true],
            ['title' => 'Spiritual Journeys', 'description' => 'Sacred destinations and peaceful retreats.', 'status' => false],
            ['title' => 'Beach Holidays', 'description' => 'Unwind along beautiful coastlines.', 'status' => true],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(['slug' => Str::slug($category['title'])], $category);
        }
    }
}
