<?php

namespace App\Filament\Resources\UsageLogs\Schemas;

use Filament\Schemas\Schema;

/**
 * رکوردها فقط از طریق سیستم ساخته می‌شوند و فرم ویرایش ندارند.
 */
class UsageLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
