<?php

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Resources\Apps\AdjustBalanceAction;
use App\Filament\Resources\Apps\AppResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditApp extends EditRecord
{
    protected static string $resource = AppResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AdjustBalanceAction::make()->after(fn () => $this->refreshFormData(['balance_display'])),
            DeleteAction::make(),
        ];
    }
}
