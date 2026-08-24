<?php

namespace Database\Factories;

use App\Enums\VendorDocumentStatus;
use App\Enums\VendorDocumentType;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorDocument>
 */
class VendorDocumentFactory extends Factory
{
    protected $model = VendorDocument::class;

    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'uploaded_by' => fn (array $attributes) => VendorUser::factory()->create([
                'vendor_id' => $attributes['vendor_id'],
            ])->getKey(),
            'document_type' => VendorDocumentType::OTHER,
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'file_path' => 'vendor-documents/'.fake()->uuid().'.txt',
            'file_name' => fake()->unique()->word().'.txt',
            'file_size' => fake()->numberBetween(1, 10_000),
            'mime_type' => 'text/plain',
            'status' => VendorDocumentStatus::PENDING,
        ];
    }
}
