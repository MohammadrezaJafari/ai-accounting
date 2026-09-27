<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Filament\Resources\Agents\AgentResource;
use App\Models\Agent;
use App\Services\Agents\AgentException;
use App\Services\Agents\AgentManifest;
use App\Services\Agents\HttpAgent;
use App\Services\Publishers\PublisherService;
use App\Support\AgentDriver;
use App\Support\AgentStatus;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditAgent extends EditRecord
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        $isHttp = fn (Agent $record) => $record->driver === AgentDriver::Http;

        return [
            Action::make('approve')->label('تأیید و انتشار')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Agent $record) => $record->status === AgentStatus::PendingReview)
                ->modalDescription(fn (Agent $record) => $record->pending_changes ? 'تغییراتی که ناشر در زمان بررسی داده هم اعمال می‌شود.' : null)
                ->schema(fn (Agent $record) => [
                    TextInput::make('revenue_share')->label('سهم ناشر')->numeric()->minValue(0)->maxValue(100)->suffix('٪')->required()
                        ->default($record->revenue_share),
                ])
                ->action(function (array $data, Agent $record) {
                    app(PublisherService::class)->approve($record, (int) $data['revenue_share']);
                    $this->fillForm();
                    Notification::make()->title('ایجنت منتشر شد و به ناشر خبر داده شد.')->success()->send();
                }),
            Action::make('applyChanges')->label('بررسی تغییرات')->icon('heroicon-o-document-magnifying-glass')->color('warning')
                ->visible(fn (Agent $record) => $record->status === AgentStatus::Approved && filled($record->pending_changes))
                ->modalHeading('تغییرات پیشنهادی ناشر')
                ->modalContent(fn (Agent $record) => self::changesTable($record))
                ->modalSubmitActionLabel('اعمال تغییرات')
                ->action(function (Agent $record) {
                    app(PublisherService::class)->applyChanges($record);
                    $this->fillForm();
                    Notification::make()->title('تغییرات منتشر شد.')->success()->send();
                }),
            Action::make('reject')->label(fn (Agent $record) => $record->status === AgentStatus::Approved ? 'رد تغییرات' : 'برگرداندن برای اصلاح')
                ->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->visible(fn (Agent $record) => $record->status === AgentStatus::PendingReview || filled($record->pending_changes))
                ->schema([
                    Textarea::make('note')->label('یادداشت برای ناشر')->required()->maxLength(1000),
                ])
                ->action(function (array $data, Agent $record) {
                    app(PublisherService::class)->reject($record, $data['note']);
                    $this->fillForm();
                    Notification::make()->title('به ناشر خبر داده شد.')->success()->send();
                }),
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

    /**
     * Current and proposed value of each changed field.
     */
    private static function changesTable(Agent $agent): HtmlString
    {
        $labels = [
            'name' => 'نام', 'tagline' => 'معرفی یک‌خطی', 'description' => 'توضیح', 'icon' => 'آیکون', 'category' => 'دسته',
            'unit_name' => 'واحد فروش', 'max_units_per_run' => 'حداکثر واحد در هر اجرا', 'endpoint_url' => 'آدرس سرویس',
            'timeout_seconds' => 'مهلت پاسخ', 'run_deadline_minutes' => 'مهلت نتیجه', 'config_schema' => 'پارامترها', 'packages' => 'بسته‌ها',
        ];
        $show = fn (mixed $value) => e(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) $value);
        $rows = collect($agent->pending_changes ?? [])->map(fn (mixed $value, string $key) => sprintf(
            '<tr style="border-top:1px solid rgb(128 128 128 / .25)"><th style="padding:8px;vertical-align:top;text-align:start;white-space:nowrap">%s</th><td style="padding:8px;vertical-align:top;opacity:.65"><pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;margin:0">%s</pre></td><td style="padding:8px;vertical-align:top"><pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;margin:0">%s</pre></td></tr>',
            e($labels[$key] ?? $key),
            $show($key === 'packages' ? app(PublisherService::class)->currentPackages($agent) : $agent->getAttribute($key)),
            $show($value),
        ))->implode('');

        return new HtmlString('<table style="width:100%;font-size:14px;border-collapse:collapse"><thead><tr><th style="padding:8px;text-align:start">فیلد</th><th style="padding:8px;text-align:start">فعلی</th><th style="padding:8px;text-align:start">پیشنهادی</th></tr></thead><tbody>'.$rows.'</tbody></table>');
    }
}
