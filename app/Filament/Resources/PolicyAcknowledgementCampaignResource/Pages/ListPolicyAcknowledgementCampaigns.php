<?php

namespace App\Filament\Resources\PolicyAcknowledgementCampaignResource\Pages;

use App\Filament\Resources\PolicyAcknowledgementCampaignResource;
use App\Models\Policy;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListPolicyAcknowledgementCampaigns extends ListRecords
{
    protected static string $resource = PolicyAcknowledgementCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('launch')->label('Launch campaign')->icon('heroicon-o-megaphone')
            ->schema([
                Select::make('policy_id')->label('Policy')->required()->searchable()
                    ->options(function () {
                        $query = Policy::query()->orderBy('name');
                        if (! auth()->user()->can('Update Policies')) {
                            $query->where('owner_id', auth()->id());
                        }

                        return $query->pluck('name', 'id');
                    }),
                TextInput::make('title')->required()->maxLength(255),
                Textarea::make('instructions')->maxLength(10000)->columnSpanFull(),
                DateTimePicker::make('due_at')->required()->minDate(now()),
                Select::make('audience_user_ids')->label('Audience')->multiple()->required()->searchable()->preload()
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')),
            ])->action(function (array $data): void {
                $policy = Policy::query()->findOrFail($data['policy_id']);
                unset($data['policy_id']);
                app(PolicyAcknowledgementManager::class)->launch($policy, auth()->user(), $data);
            })];
    }
}
