<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            return;
        }

        $replacements = [
            'general.name' => ['OpenGRC', 'Fynix Cyber Audit'],
            'mail.from' => ['no-reply@opengrc.com', 'no-reply@localhost'],
            'mail.templates.password_reset_subject' => ['OpenGRC Password Reset', 'Fynix Cyber Audit Password Reset'],
            'mail.templates.new_account_subject' => ['OpenGRC Account Created', 'Fynix Cyber Audit Account Created'],
            'mail.templates.evidence_request_subject' => ['OpenGRC Evidence Request', 'Fynix Cyber Audit Evidence Request'],
        ];

        $decode = static function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        };

        foreach ($replacements as $key => [$from, $to]) {
            $row = DB::table('settings')->where('key', $key)->first();
            if (! $row) {
                continue;
            }

            if ($decode($row->value) === $from) {
                DB::table('settings')->where('key', $key)->update(['value' => json_encode($to)]);
            }
        }

        DB::table('settings')
            ->whereIn('key', [
                'mail.templates.password_reset_body',
                'mail.templates.new_account_body',
                'mail.templates.evidence_request_body',
            ])
            ->get()
            ->each(function ($row) use ($decode) {
                $value = $decode($row->value);
                if (! is_string($value) || ! str_contains($value, 'OpenGRC')) {
                    return;
                }

                DB::table('settings')->where('id', $row->id)->update([
                    'value' => json_encode(str_replace('OpenGRC', 'Fynix Cyber Audit', $value)),
                ]);
            });
    }

    public function down(): void
    {
        // Irreversible product rename.
    }
};
