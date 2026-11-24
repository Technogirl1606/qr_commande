<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaydunyaService
{
    protected string $baseUrl;
    protected array $headers;

    public function __construct()
    {
        $mode = config('services.paydunya.mode', 'test');

        // URL selon le mode (test ou production)
        $this->baseUrl = $mode === 'live'
            ? 'https://app.paydunya.com/api/v1'
            : 'https://app.paydunya.com/sandbox-api/v1';

        $this->headers = [
            'PAYDUNYA-MASTER-KEY'  => (string) config('services.paydunya.master_key'),
            'PAYDUNYA-PRIVATE-KEY' => (string) config('services.paydunya.private_key'),
            'PAYDUNYA-TOKEN'       => (string) config('services.paydunya.token'),
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json',
        ];
    }

    /**
     * Créer une facture de paiement PayDunya.
     * Accepte soit un tableau associatif, soit des arguments séparés.
     */
    public function createInvoice(
        mixed $totalOrData,
        ?string $description = null,
        ?string $returnUrl = null,
        ?string $cancelUrl = null,
        ?string $callbackUrl = null,
        array $customData = []
    ): ?array {
        if (is_array($totalOrData)) {
            $totalAmount = $totalOrData['total_amount'] ?? 0;
            $description = $totalOrData['description'] ?? 'Commande';
            $returnUrl   = $totalOrData['return_url'] ?? route('menu');
            $cancelUrl   = $totalOrData['cancel_url'] ?? $returnUrl;
            $callbackUrl = $totalOrData['callback_url'] ?? route('paydunya.notification');
            $customData  = $totalOrData['custom_data'] ?? [];
        } else {
            $totalAmount = (float) $totalOrData;
            $description = $description ?? 'Commande';
            $returnUrl   = $returnUrl ?? route('menu');
            $cancelUrl   = $cancelUrl ?? $returnUrl;
            $callbackUrl = $callbackUrl ?? route('paydunya.notification');
        }

        $payload = [
            'invoice' => [
                'total_amount' => (float) $totalAmount,
                'description'  => $description,
            ],
            'store' => [
                'name'    => config('services.paydunya.store_name', config('app.name', 'Restaurant')),
                'tagline' => 'Commande en ligne sur table',
            ],
            'actions' => [
                'cancel_url'   => $cancelUrl,
                'return_url'   => $returnUrl,
                'callback_url' => $callbackUrl,
            ],
            'custom_data' => $customData,
        ];

        try {
            $response = Http::withHeaders($this->headers)
                ->timeout(15)
                ->post("{$this->baseUrl}/checkout-invoice/create", $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['response_code']) && $data['response_code'] === '00') {
                return [
                    'status'       => 'success',
                    'token'        => $data['token'] ?? null,
                    'url'          => $data['response_text'] ?? null,
                    'checkout_url' => $data['response_text'] ?? null,
                ];
            }

            Log::error('Erreur PayDunya création facture : ', $data ?? [$response->body()]);
            return [
                'status'  => 'error',
                'message' => $data['response_text'] ?? 'Erreur lors de la création de la facture PayDunya.',
            ];
        } catch (\Exception $e) {
            Log::error('Exception PayDunya createInvoice : ' . $e->getMessage());
            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifier le statut d'un paiement PayDunya via son token.
     */
    public function confirmInvoice(string $token): ?array
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->timeout(15)
                ->get("{$this->baseUrl}/checkout-invoice/confirm/{$token}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erreur PayDunya confirmation facture : ', $response->json() ?? [$response->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('Exception PayDunya confirmInvoice : ' . $e->getMessage());
            return null;
        }
    }
}
