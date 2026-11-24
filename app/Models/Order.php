<?php

namespace App\Models;

use App\OrderStatus;
use App\OrderType;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    /**
     * "public_id" n'est volontairement pas dans cette liste : il est généré
     * par le serveur à la création, jamais fourni par une requête.
     */
    protected $fillable = [
        'table_label',
        'order_type',
        'customer_name',
        'customer_phone',
        'customer_district',
        'note',
        'status',
        'total',
        'payment_status',
        'payment_reference',
        'payment_url',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status'         => OrderStatus::class,
            'order_type'     => OrderType::class,
            'payment_status' => PaymentStatus::class,
            'total'          => 'decimal:2',
            'paid_at'        => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->public_id)) {
                $order->public_id = (string) Str::ulid();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Filtre réutilisable : Order::paid()->get() ne retourne que les commandes
     * dont le paiement est confirmé (celles que la cuisine doit voir).
     */
    public function scopePaid(Builder $query): void
    {
        $query->where('payment_status', PaymentStatus::Paye);
    }


    /**
     * Numéro de ticket lisible, affiché au client et en cuisine (ex : #014).
     * Basé sur l'id — pas de colonne dédiée à gérer ni de risque de collision.
     */
    public function ticketNumber(): string
    {
        return '#'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }
}