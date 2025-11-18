<?php

namespace Tedon\Tooner\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string encode(mixed $value, array $options = [])
 * @method static mixed decode(string $value, array $options = [])
 * @method static mixed getConfig(string $key = null)
 *
 * @see \Tedon\Tooner\Tooner
 */
class Tooner extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'tooner';
    }
}