<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Order;
use App\Models\Table;
use App\OrderStatus;
use App\OrderType;
use App\PaymentStatus;
use App\Services\PaydunyaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Route publique : statut d'une commande, pour le suivi côté client.
     */
    public function status(Order $order): JsonResponse
    {
        return response()->json([
            'id' => $order->id,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'number' => $order->ticketNumber(),
            'total' => (float) $order->total,
            'payment_url' => $order->payment_status === PaymentStatus::EnAttente ? $order->payment_url : null,
        ]);
    }

    /**
     * Crée une commande et génère la facture PayDunya (Wave / Orange Money).
     */
    public function store(Request $request, PaydunyaService $paydunya): JsonResponse
    {
        $validated = $request->validate([
            'table_code'        => 'nullable|string',
            'table_label'       => 'nullable|string',
            'order_type'        => 'required|in:sur_table,a_emporter,livraison',
            'customer_name'     => 'required_if:order_type,a_emporter,livraison|nullable|string|max:100',
            'customer_phone'    => 'required_if:order_type,a_emporter,livraison|nullable|string|max:30',
            'customer_district' => 'required_if:order_type,livraison|nullable|string|max:100',
            'items'             => 'required|array|min:1',
            'items.*.item_id'   => 'required|integer',
            'items.*.quantite'  => 'required|integer|min:1',
            'note'              => 'nullable|string',
        ]);

        $table = null;
        if (!empty($validated['table_code'])) {
            $table = Table::where('code', $validated['table_code'])->first();
        }

        $orderType  = OrderType::from($validated['order_type']);
        $tableLabel = $table ? $table->name : ($validated['table_label'] ?? null);

        $order = DB::transaction(function () use ($validated, $tableLabel, $orderType) {
            $order = Order::create([
                'table_label'       => $tableLabel,
                'order_type'        => $orderType,
                'customer_name'     => $validated['customer_name'] ?? null,
                'customer_phone'    => $validated['customer_phone'] ?? null,
                'customer_district' => $validated['customer_district'] ?? null,
                'note'              => $validated['note'] ?? null,
                'status'            => OrderStatus::Nouvelle,
                'total'             => 0,
                'payment_status'    => PaymentStatus::EnAttente,
            ]);

            $total = 0;
            foreach ($validated['items'] as $line) {
                $item = Item::available()->findOrFail($line['item_id']);
                $order->items()->create([
                    'item_id'  => $item->id,
                    'name'     => $item->name,
                    'price'    => $item->price,
                    'quantite' => $line['quantite'],
                ]);
                $total += $item->price * $line['quantite'];
            }

            $order->update(['total' => $total]);
            return $order;
        });

        // Préparation des URLs de redirection
        $menuBaseUrl = $table ? route('menu.table', $table->code) : route('menu');
        $successUrl  = $menuBaseUrl . '?commande=' . $order->public_id;
        $cancelUrl   = $menuBaseUrl . '?commande=' . $order->public_id . '&error=1';
        $callbackUrl = route('paydunya.notification');

        $invoice = $paydunya->createInvoice(
            totalOrData: (float) $order->total,
            description: "Commande {$order->ticketNumber()} - {$tableLabel}",
            returnUrl: $successUrl,
            cancelUrl: $cancelUrl,
            callbackUrl: $callbackUrl,
            customData: [
                'order_id' => $order->id,
                'public_id' => $order->public_id,
            ]
        );

        if ($invoice && ($invoice['status'] ?? '') === 'success') {
            $order->update([
                'payment_reference' => $invoice['token'],
                'payment_url' => $invoice['url'],
            ]);

            return response()->json([
                'order' => [
                    'id' => $order->id,
                    'number' => $order->ticketNumber(),
                    'total' => (float) $order->total,
                    'status' => $order->status->value,
                ],
                'checkout_url' => $invoice['url'],
                'payment_url'  => $invoice['url'],
            ]);
        }

        // Si PayDunya a échoué (pas d'internet, serveur injoignable, etc.),
        // on supprime la commande créée pour ne pas laisser de commande fantôme.
        $order->items()->delete();
        $order->delete();

        return response()->json([
            'message' => $invoice['message'] ?? 'Impossible de joindre le service de paiement PayDunya. Veuillez vérifier votre connexion internet.',
        ], 422);
    }

    /**
     * IPN / Webhook appelé par PayDunya en arrière-plan.
     */
    public function notification(Request $request, PaydunyaService $paydunya): JsonResponse
    {
        $token = $request->input('data.invoice.token') 
            ?? $request->input('token') 
            ?? $request->input('invoice_token');

        if (!$token) {
            return response()->json(['error' => 'Token manquant'], 400);
        }

        $confirmation = $paydunya->confirmInvoice($token);

        if ($confirmation && ($confirmation['status'] ?? '') === 'completed') {
            $customData = $confirmation['custom_data'] ?? [];
            $orderId = $customData['order_id'] ?? null;
            $publicId = $customData['public_id'] ?? null;

            $order = null;
            if ($orderId) {
                $order = Order::find($orderId);
            } elseif ($publicId) {
                $order = Order::where('public_id', $publicId)->first();
            } else {
                $order = Order::where('payment_reference', $token)->first();
            }

            if ($order) {
                $order->update([
                    'payment_status' => PaymentStatus::Paye,
                    'paid_at' => now(),
                    'status' => OrderStatus::Nouvelle,
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }
}
