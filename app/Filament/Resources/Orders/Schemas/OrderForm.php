<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Schemas\Schema;

/**
 * رکوردها فقط از طریق سیستم ساخته می‌شوند و فرم ویرایش ندارند.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
