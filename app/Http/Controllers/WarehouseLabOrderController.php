<?php

namespace App\Http\Controllers;

use App\Services\WarehouseOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WarehouseLabOrderController extends Controller
{
    /**
     * List recent confirmed Dene Healthcare orders.
     */
    public function index(Request $request, WarehouseOrders $orders): JsonResponse
    {
        $query = $request->string('q')->trim()->limit(80)->toString();

        return $this->respond(fn (): array => [
            'orders' => $orders->search($query),
        ]);
    }

    /**
     * Turn one confirmed order into warehouse locations for a picking run.
     */
    public function show(int $order, WarehouseOrders $orders): JsonResponse
    {
        return $this->respond(fn (): array => $orders->run($order));
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (RuntimeException $exception) {
            $message = $this->safeMessage($exception->getMessage());
            $status = match ($message) {
                'Connect the Dene Healthcare Odoo connection before loading orders. The MidMeds connection is not used here.' => 422,
                'That order was not found.' => 404,
                default => 502,
            };

            return response()->json(['message' => $message], $status);
        }
    }

    private function safeMessage(string $message): string
    {
        return in_array($message, [
            'Connect the Dene Healthcare Odoo connection before loading orders. The MidMeds connection is not used here.',
            'Could not read Dene Healthcare orders from Odoo.',
            'That order was not found.',
        ], true) ? $message : 'Could not read Dene Healthcare orders from Odoo.';
    }
}
