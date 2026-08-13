<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Models\Standard;
use App\Support\LocalBundleCatalog;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Storage;

class BundleController extends Controller
{
    public static function generate($code): array
    {
        try {
            $standard = Standard::where('code', $code)->with('controls')->firstOrFail();
            $filePath = 'bundlegen/'.$code.'.json';
            Storage::disk('private')->put($filePath, json_encode($standard));

            return ['success' => 'Bundle generated successfully! Saved to storage/app/private/'.$filePath];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function retrieve(): void
    {
        $entries = LocalBundleCatalog::entries();

        if ($entries === []) {
            Notification::make()
                ->title('No local bundles')
                ->body('No packs were found in resources/bundles/catalog.json.')
                ->color('danger')
                ->send();

            return;
        }

        foreach ($entries as $bundle) {
            Bundle::updateOrCreate(
                ['code' => $bundle['code']],
                [
                    'code' => $bundle['code'],
                    'name' => $bundle['name'],
                    'version' => $bundle['version'] ?? '',
                    'authority' => $bundle['authority'] ?? '',
                    'description' => $bundle['description'] ?? '',
                    'repo_url' => 'local://'.$bundle['code'],
                    'type' => $bundle['type'] ?? 'Standard',
                ]
            );
        }

        Notification::make()
            ->title('Local catalog loaded')
            ->body('Bundles were refreshed from the packs shipped with Fynix Cyber Audit.')
            ->send();
    }

    public static function importBundle(Bundle $bundle): void
    {
        \Log::info('Importing local bundle: '.$bundle->code);

        try {
            $entry = LocalBundleCatalog::find($bundle->code);
            if ($entry === null) {
                throw new Exception('This pack is not in the local catalog.');
            }

            if (! empty($entry['file'])) {
                self::importFromLocalJson($bundle, LocalBundleCatalog::pathFor($entry['file']));
            } elseif (! empty($entry['seeder'])) {
                Artisan::call('db:seed', [
                    '--class' => $entry['seeder'],
                    '--force' => true,
                ]);
            } else {
                throw new Exception('Catalog entry has no file or seeder.');
            }

            $bundle->update(['status' => 'imported']);
        } catch (Exception $e) {
            \Log::error('Bundle import error', [
                'bundle' => $bundle->code,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Bundle Import Failed')
                ->body($e->getMessage())
                ->color('danger')
                ->send();

            return;
        }

        Notification::make()
            ->title('Bundle imported')
            ->body($bundle->code.' was imported from the local catalog.')
            ->send();
    }

    private static function importFromLocalJson(Bundle $bundle, string $path): void
    {
        if (! is_file($path)) {
            throw new Exception('Local bundle file is missing: '.$path);
        }

        $bundle_content = json_decode((string) file_get_contents($path), true);
        if (! is_array($bundle_content) || ! isset($bundle_content['code'], $bundle_content['controls'])) {
            throw new Exception('Bundle JSON is missing required fields (code or controls)');
        }

        $standard = Standard::updateOrCreate(
            ['code' => $bundle_content['code']],
            [
                'code' => $bundle_content['code'],
                'name' => $bundle_content['name'],
                'authority' => $bundle_content['authority'],
                'description' => $bundle_content['description'],
            ]
        );

        foreach ($bundle_content['controls'] as $control) {
            $standard->controls()->updateOrCreate(
                ['code' => $control['code']],
                [
                    'title' => $control['title'],
                    'code' => $control['code'],
                    'description' => $control['description'],
                    'discussion' => $control['discussion'] ?? null,
                    'test' => $control['test'] ?? null,
                    'type' => $control['type'],
                    'category' => $control['category'],
                    'enforcement' => $control['enforcement'],
                ]
            );
        }
    }
}
