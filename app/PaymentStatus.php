<?php

namespace App;

enum PaymentStatus: string
{
    case EnAttente = 'en_attente';
    case Paye = 'paye';
    case Echoue = 'echoue';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Paye => 'Payé',
            self::Echoue => 'Échoué',
            self::Annule => 'Annulé',
        };
    }
}
