<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Filament\Resources\Agents\AgentResource;
use App\Models\Agent;
use App\Services\Agents\AgentException;
use App\Services\Agents\AgentManifest;
use App\Services\Agents\HttpAgent;
use App\Support\AgentDriver;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAgent extends EditRecord
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        $isHttp = fn (Agent $record) => $record->driver === AgentDriver::Http;

        return [
            Action::make('ping')->label('تست اتصال')->icon('heroicon-o-signal')->color('gray')
                ->visible($isHttp)
                ->action(function (Agent $record) {
                    $error = app(HttpAgent::class)->ping($record);

                    $error === null
                        ? Notification::make()->title('سرویس ایجنت پاسخ داد.')->success()->send()
                        : Notification::make()->title('اتصال برقرار نشد')->body($error)->danger()->send();
                }),
            ActionGroup::make([
                Action::make('importManifest')->label('بارگذاری از manifest')->icon('heroicon-o-arrow-down-tray')
                    ->modalDescription('مشخصات و پارامترهای ایجنت از فایل JSON ناشر خوانده و جایگزین مقدارهای فعلی می‌شوند.')
                    ->schema([
                        TextInput::make('url')->label('آدرس manifest')->url()->required()->extraInputAttributes(['dir' => 'ltr'])
                            ->placeholder('https://agent.example.com/manifest.json'),
                    ])
                    ->action(function (array $data, Agent $record) {
                        try {
                            $record->update(app(AgentManifest::class)->fetch($data['url']));
                        } catch (AgentException $e) {
                            Notification::make()->title('manifest خوانده نشد')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $this->fillForm();
                        Notification::make()->title('مشخصات ایجنت از manifest به‌روز شد.')->success()->send();
                    }),
                Action::make('rotateSecret')->label('ساخت کلید امضای جدید')->icon('heroicon-o-key')->color('warning')
                    ->visible($isHttp)
                    ->requiresConfirmation()
                    ->modalDescription('کلید فعلی بلافاصله از کار می‌افتد و سرویس ایجنت باید کلید جدید را بگیرد.')
                    ->action(function (Agent $record) {
                        $record->forceFill(['signing_secret' => Agent::newSigningSecret()])->save();
                        $this->fillForm();
                        Notification::make()->title('کلید جدید ساخته شد. آن را از بخش «اتصال» کپی کنید.')->success()->send();
                    }),
                DeleteAction::make(),
            ]),
        ];
    }
}
