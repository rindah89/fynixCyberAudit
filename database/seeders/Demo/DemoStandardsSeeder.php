<?php

namespace Database\Seeders\Demo;

use App\Http\Controllers\BundleController;
use App\Models\Bundle;
use App\Models\Standard;
use Illuminate\Database\Seeder;

class DemoStandardsSeeder extends Seeder
{
    public function __construct(private DemoContext $context) {}

    public function run(): void
    {
        BundleController::retrieve();

        $bundle = Bundle::where('code', 'TSC-2017')->first();
        if ($bundle) {
            BundleController::importBundle($bundle);
        }

        $tscStandard = Standard::where('code', 'TSC-2017')->first();

        if ($tscStandard) {
            $this->context->standards[] = $tscStandard;
            foreach ($tscStandard->controls as $control) {
                $control->update(['control_owner_id' => $this->context->users[array_rand($this->context->users)]->id]);
                $this->context->controls[] = $control;
                $this->context->tscControls[] = $control;
            }
        }
    }
}
