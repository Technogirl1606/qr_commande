<?php

namespace App;

enum OrderType: string
{
    case SurTable   = 'sur_table';
    case AEmporter  = 'a_emporter';
    case Livraison  = 'livraison';

    public function label(): string
    {
        return match ($this) {
            self::SurTable  => '🪑 Sur table',
            self::AEmporter => '🥡 À emporter',
            self::Livraison => '🛵 Livraison',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::SurTable  => '🪑',
            self::AEmporter => '🥡',
            self::Livraison => '🛵',
        };
    }
}
